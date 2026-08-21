<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file came back out of the trash. Its name may differ from the one it went in with: a later upload can have taken it.
 */
final class FileRestored extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
