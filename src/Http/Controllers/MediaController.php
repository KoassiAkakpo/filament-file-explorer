<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use ZipArchive;

class MediaController extends Controller
{
    /**
     * Upper bound for a selection archive: the ids travel in the query string,
     * and an unbounded selection is an easy way to tie up a worker.
     */
    protected const MAX_SELECTION = 500;

    /**
     * Temp copies of remote-disk media staged for ZipArchive, deleted once the
     * archive is closed.
     *
     * @var list<string>
     */
    protected array $stagedFiles = [];

    public function show(Request $request, string $scopeKey, Media $media): Response
    {
        $this->authorizeMedia($scopeKey, $media);

        return $this->fileResponse(
            $media,
            $request->boolean('download')
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT
                : ResponseHeaderBag::DISPOSITION_INLINE,
            $this->conversionName($request->query('conversion'), $media),
        );
    }

    /**
     * Which rendition to serve, or null for the original.
     *
     * Conversions go out through this route like everything else, because
     * $media->getUrl('thumbnail') is a raw disk URL: on a public disk it hands
     * the file to anyone who has the link, with neither the ability check nor
     * the containment check the route exists to enforce.
     *
     * A name is only honoured when the media really has that conversion, so it
     * can only ever be one already recorded in generated_conversions — never a
     * path arriving from the query string. Missing conversions fall through to
     * the original, which keeps the explorer working before a regenerate.
     */
    protected function conversionName(mixed $requested, Media $media): ?string
    {
        if (! is_string($requested) || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $requested)) {
            return null;
        }

        return $media->hasGeneratedConversion($requested) ? $requested : null;
    }

    public function zipMedia(Request $request, string $scopeKey, Media $media): Response
    {
        $this->authorizeMedia($scopeKey, $media);

        return $this->streamZip(
            function (ZipArchive $zip) use ($media): void {
                $this->addMediaToZip($zip, $media, $this->zipEntryName((string) $media->file_name));
            },
            $this->zipDownloadName(pathinfo((string) $media->file_name, PATHINFO_FILENAME), 'file'),
        );
    }

    /**
     * Archive of an arbitrary selection of folders and files.
     *
     * Downloading several items at once used to send only the first one.
     */
    public function zipSelection(Request $request, string $scopeKey): Response
    {
        $rootFolderIds = $this->authorizeScope($scopeKey);

        $folderIds = $this->idList($request->query('folders'));
        $fileIds = $this->idList($request->query('files'));

        abort_if($folderIds === [] && $fileIds === [], 400);
        abort_if(count($folderIds) + count($fileIds) > self::MAX_SELECTION, 400);

        return $this->streamZip(
            function (ZipArchive $zip) use ($folderIds, $fileIds, $rootFolderIds): void {
                $tree = app(FolderTree::class);

                foreach ($folderIds as $folderId) {
                    $folder = Folder::query()->find($folderId);

                    // A row that vanished since the selection was made is
                    // skipped; one that was never in scope is a forged id.
                    if (! $folder instanceof Folder) {
                        continue;
                    }

                    abort_unless($tree->isUnderAnyRoot($folder, $rootFolderIds), 403);

                    $this->addFolderToZip($zip, $folder, $this->zipEntryName((string) ($folder->name ?: 'folder')));
                }

                foreach ($fileIds as $fileId) {
                    $media = Media::query()->find($fileId);

                    if (! $media instanceof Media) {
                        continue;
                    }

                    abort_unless($this->mediaBelongsToRoot($media, $rootFolderIds), 403);

                    $this->addMediaToZip($zip, $media, $this->zipEntryName((string) $media->file_name));
                }
            },
            'selection.zip',
        );
    }

    public function zipFolder(Request $request, string $scopeKey, Folder $folder): Response
    {
        // The root comes from the scope, never from the request. With an
        // attacker-supplied root the containment check below would prove
        // nothing, since every folder sits under its own ancestor.
        $rootFolderIds = $this->authorizeScope($scopeKey);

        abort_unless(app(FolderTree::class)->isUnderAnyRoot($folder, $rootFolderIds), 403);

        return $this->streamZip(
            function (ZipArchive $zip) use ($folder): void {
                $this->addFolderToZip($zip, $folder, $this->zipEntryName((string) ($folder->name ?: 'folder')));
            },
            $this->zipDownloadName((string) $folder->name, 'archive'),
        );
    }

    /**
     * Root folders the caller may browse under $scopeKey, having passed both the
     * access check and the download ability.
     *
     * @return list<int>
     */
    protected function authorizeScope(string $scopeKey): array
    {
        $rootFolderIds = ScopeRoots::resolveAll($scopeKey);

        abort_if($rootFolderIds === [], 403);

        $authorizer = app(FileExplorerAuthorizer::class);

        // A scope is one authorization unit however many roots it offers: they
        // share the scope key, so they share the decision and the ability set.
        $primary = $rootFolderIds[0];

        abort_unless($authorizer->canAccess($scopeKey, $primary), 403);
        abort_unless(app(Abilities::class)->allows($scopeKey, $primary, 'download'), 403);

        return $rootFolderIds;
    }

    /**
     * @return list<int>
     */
    protected function authorizeMedia(string $scopeKey, Media $media): array
    {
        $rootFolderIds = $this->authorizeScope($scopeKey);

        abort_unless($this->mediaBelongsToRoot($media, $rootFolderIds), 403);

        return $rootFolderIds;
    }

    /**
     * @param  list<int>  $rootFolderIds
     */
    protected function mediaBelongsToRoot(Media $media, array $rootFolderIds): bool
    {
        // Explorer media always hangs off a Folder in the explorer collection.
        // Without these two checks, a media row belonging to another model whose
        // model_id happens to match an in-scope folder id would pass containment.
        if ($media->model_type !== (new Folder)->getMorphClass()) {
            return false;
        }

        if ($media->collection_name !== UploadRules::collection()) {
            return false;
        }

        $folder = Folder::query()->find($media->model_id);

        return $folder instanceof Folder
            && app(FolderTree::class)->isUnderAnyRoot($folder, $rootFolderIds);
    }

    /**
     * @return list<int>
     */
    protected function idList(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $ids = array_map('intval', explode(',', $value));

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    protected function fileResponse(Media $media, string $disposition, ?string $conversion = null): Response
    {
        $fileName = basename((string) $media->file_name) ?: 'file';

        // A conversion is always an image, whatever the original was: a PDF
        // thumbnail served as application/pdf would not render.
        $contentType = $conversion === null
            ? ($media->mime_type ?: 'application/octet-stream')
            : ($this->conversionMimeType($media, $conversion) ?: 'image/jpeg');

        // Local disks go out as a file response so Symfony handles Range and
        // If-Range — video seeking, resumable downloads — plus Content-Length.
        if ($media->getDiskDriverName() === 'local') {
            $path = $conversion === null ? $media->getPath() : $media->getPath($conversion);

            abort_unless(is_file($path), 404);

            $response = new BinaryFileResponse($path, Response::HTTP_OK, [
                'Content-Type' => $contentType,
            ]);

            $response->setContentDisposition($disposition, $fileName);
            $response->setAutoLastModified();

            return $response;
        }

        // Remote disk: stream the object instead of assuming a local path.
        $path = $conversion === null
            ? $media->getPathRelativeToRoot()
            : $media->getPathRelativeToRoot($conversion);

        $disk = Storage::disk($conversion === null ? $media->disk : ($media->conversions_disk ?: $media->disk));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $fileName, ['Content-Type' => $contentType], $disposition);
    }

    /**
     * Guessed from the conversion's own extension: Media Library keeps the
     * original's unless the conversion changed the format.
     */
    protected function conversionMimeType(Media $media, string $conversion): ?string
    {
        $extension = strtolower(pathinfo($media->getPath($conversion), PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            default => null,
        };
    }

    /**
     * @param  callable(ZipArchive): void  $fill
     */
    protected function streamZip(callable $fill, string $downloadName): Response
    {
        $archive = tempnam(sys_get_temp_dir(), 'fezip_');

        abort_unless($archive !== false, 500);

        // The archive outlives this method: the callback below streams it after
        // the response is sent, and unlinks it there. A client aborting
        // mid-download never reaches that point, hence the shutdown backstop.
        register_shutdown_function(static function () use ($archive): void {
            @unlink($archive);
        });

        try {
            $zip = new ZipArchive;

            abort_unless($zip->open($archive, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true, 500);

            $fill($zip);
            $zip->close();
        } finally {
            $this->cleanStagedFiles();
        }

        $size = filesize($archive);

        // Streamed in chunks rather than read into a string: a folder archive is
        // routinely larger than the memory limit.
        return response()->streamDownload(function () use ($archive): void {
            $handle = fopen($archive, 'rb');

            if ($handle === false) {
                return;
            }

            try {
                while (! feof($handle)) {
                    echo fread($handle, 512 * 1024);
                    flush();
                }
            } finally {
                fclose($handle);
                @unlink($archive);
            }
        }, $downloadName, array_filter([
            'Content-Type' => 'application/zip',
            'Content-Length' => $size === false ? null : (string) $size,
        ]));
    }

    protected function addFolderToZip(ZipArchive $zip, Folder $folder, string $prefix): void
    {
        foreach ($folder->getMedia(UploadRules::collection()) as $media) {
            $this->addMediaToZip($zip, $media, $prefix.'/'.$this->zipEntryName((string) $media->file_name));
        }

        foreach ($folder->children as $child) {
            $this->addFolderToZip($zip, $child, $prefix.'/'.$this->zipEntryName((string) ($child->name ?: 'folder')));
        }
    }

    protected function addMediaToZip(ZipArchive $zip, Media $media, string $entryName): void
    {
        if ($media->getDiskDriverName() === 'local') {
            $path = $media->getPath();

            if (is_file($path)) {
                $zip->addFile($path, $entryName);
            }

            return;
        }

        // ZipArchive needs a local file, so a remote object is copied through a
        // stream — never into memory — and kept until the archive is closed.
        $source = $media->stream();

        if (! is_resource($source)) {
            return;
        }

        $staged = tempnam(sys_get_temp_dir(), 'femedia_');
        $target = $staged === false ? false : fopen($staged, 'wb');

        if ($target === false) {
            fclose($source);

            if ($staged !== false) {
                @unlink($staged);
            }

            return;
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        $this->stagedFiles[] = $staged;
        $zip->addFile($staged, $entryName);
    }

    protected function cleanStagedFiles(): void
    {
        foreach ($this->stagedFiles as $path) {
            @unlink($path);
        }

        $this->stagedFiles = [];
    }

    /**
     * Folder and file names are user input, so each becomes a single archive
     * path segment: a name containing "../" must not escape on extraction.
     *
     * Separators are what make traversal possible; once they are gone the name
     * is one segment, and only an all-dots segment still means anything to an
     * extractor.
     */
    protected function zipEntryName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '-', $name));

        return trim($name, '.') === '' ? 'item' : $name;
    }

    protected function zipDownloadName(string $name, string $fallback): string
    {
        $slug = Str::slug($name);

        return ($slug !== '' ? $slug : $fallback).'.zip';
    }
}
