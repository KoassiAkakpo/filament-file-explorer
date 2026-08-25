<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Exceptions\ChunkedUploadFailed;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\ChunkedUploads;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;
use Koassi\FilamentFileExplorer\Support\UploadLimits;

/**
 * The two requests a sliced upload is made of.
 *
 * `begin` decides, `chunk` carries. Everything that is a decision — the scope,
 * the ability, the containment walk, the size, an early look at the quota —
 * happens once in `begin`, and the slices that follow prove only that they hold
 * a token this session was given. That is the same split a share link makes, and
 * for the same reason: repeating a decision per slice would be a hundred
 * authorizer calls for one file, and none of them would be the authoritative one
 * anyway. **The authoritative checks are still in `Livewire\FileExplorer`**, at
 * the write, exactly where they were before any of this existed — this route
 * refuses early so nobody spends ten minutes uploading a file that was never
 * going to land.
 */
class UploadChunkController extends Controller
{
    public function begin(Request $request, string $scopeKey): JsonResponse
    {
        // A transport that is not in use is not a transport that answers. The
        // browser is told the same thing through the component, so this is the
        // second half of one answer rather than a check of its own.
        abort_unless(ChunkedUploads::enabled(), 404);

        $rootFolderIds = $this->authorizeScope($scopeKey);

        $validated = $request->validate([
            'folder' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        $folder = FolderModel::query()->find($validated['folder']);

        abort_unless($folder instanceof Folder, 404);

        // Containment, against the roots the scope resolved to and never against
        // anything derived from the folder itself, which would only prove it sits
        // under its own ancestor.
        $tree = app(FolderTree::class);
        $rootFolderId = null;

        foreach ($rootFolderIds as $candidate) {
            if ($tree->isUnderRoot($folder, $candidate)) {
                $rootFolderId = $candidate;

                break;
            }
        }

        abort_if($rootFolderId === null, 403);

        $size = (int) $validated['size'];
        $limit = UploadLimits::maxUploadBytes();

        abort_if($limit !== null && $size > $limit, 413);
        abort_if($size > ChunkedUploads::MAX_CHUNKS * UploadLimits::chunkBytes(), 413);

        // An early look at the quota, so a file with no room to land is refused
        // before its first byte rather than after its last. Advisory: the gate
        // that decides is the one in updatedFiles(), which reserves per file and
        // knows what the conflict policy frees.
        abort_if(! app(Quota::class)->fits($rootFolderId, $size), 413);

        return new JsonResponse(app(ChunkedUploads::class)->begin(
            $scopeKey,
            $rootFolderId,
            (int) $folder->id,
            (string) $validated['name'],
            $size,
        ));
    }

    /**
     * One slice. The response of the last one carries the signed reference the
     * browser hands to Livewire, which is where this stops being involved.
     */
    public function chunk(Request $request, string $token, int $index): JsonResponse
    {
        abort_unless(ChunkedUploads::enabled(), 404);
        abort_if($index < 0 || $index >= ChunkedUploads::MAX_CHUNKS, 400);

        $chunk = $request->file('chunk');

        // No `mimes` and no `max` here on purpose. A slice of a PDF is not a
        // PDF, and the file this is part of is checked whole — by the rules in
        // the component, on bytes that are all present.
        abort_unless($chunk !== null && ! is_array($chunk) && $chunk->isValid(), 400);

        $uploads = app(ChunkedUploads::class);

        // The token is the credential, and it names a record only this session
        // holds. Nothing here reads a scope key or a folder id off the request:
        // begin() already decided, and repeating the decision from client input
        // would be the way to get a different answer than the one it gave.
        if ($uploads->record($token) === null) {
            throw ChunkedUploadFailed::gone();
        }

        return new JsonResponse($uploads->append($token, $index, $chunk));
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        app(ChunkedUploads::class)->discard($token);

        return new JsonResponse(['cancelled' => true]);
    }

    /**
     * The roots a scope offers, once the authorizer has let this user in.
     *
     * `upload` rather than `download`: this is the media routes' check with the
     * ability that matches what the request is for.
     *
     * @return list<int>
     */
    protected function authorizeScope(string $scopeKey): array
    {
        $rootFolderIds = ScopeRoots::resolveAll($scopeKey);

        abort_if($rootFolderIds === [], 403);

        $primary = $rootFolderIds[0];

        abort_unless(app(FileExplorerAuthorizer::class)->canAccess($scopeKey, $primary), 403);
        abort_unless(app(Abilities::class)->allows($scopeKey, $primary, 'upload'), 403);

        return $rootFolderIds;
    }
}
