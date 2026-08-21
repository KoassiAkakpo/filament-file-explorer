<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file was stored in a folder. Fired once per file, so a multi-file upload fires several times.
 */
final class FileUploaded extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly Folder $folder,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
