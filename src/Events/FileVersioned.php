<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file was uploaded over another, and the one that was there was kept.
 *
 * Fired instead of FileDeleted, which the `replace` policy used to fire because
 * replacing really did destroy the old file — "a listener has to hear that as a
 * deletion of its own" was true then and is a lie now. A listener watching for
 * deletions must not be told a file was lost when its bytes are still on the
 * disk and one click away.
 */
final class FileVersioned extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly int $versionedMediaId,
        public readonly string $lineage,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
