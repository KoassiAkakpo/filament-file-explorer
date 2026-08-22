<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\FileShare;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file was given a public link.
 *
 * Worth hearing about even where nothing else is: this is the one action that
 * makes a file readable without a session, so it is the one an audit trail is
 * most often built for.
 */
final class FileShared extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly FileShare $share,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
