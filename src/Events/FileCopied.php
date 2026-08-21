<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file was duplicated. The copy is a new media row with its own name; the source is untouched.
 */
final class FileCopied extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $copy,
        public readonly Media $source,
        public readonly Folder $folder,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
