<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turning one media row into a response.
 *
 * Shared by the media routes and the public share route rather than copied into
 * each. What lives here is not presentation: it is which conversion names may be
 * honoured, what content type a conversion goes out as, and the local-versus-
 * remote disk split that keeps video seeking working. A second copy is how one
 * of those rules gets fixed in one place and left wrong in the other.
 *
 * It answers nothing about *whether* the caller may have the file. Each route
 * decides that for itself before calling in here.
 */
trait ServesMediaFiles
{
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
}
