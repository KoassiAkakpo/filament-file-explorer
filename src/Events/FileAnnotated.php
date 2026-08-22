<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A file's description or tags changed.
 *
 * One event for both, because they are one panel and one ability: a listener
 * keeping an audit trail wants to know the annotation moved, and both the
 * before and the after are here to say how. Tag names rather than rows, since a
 * tag nothing carries any more is dropped and the row would be gone by the time
 * a queued listener looked for it.
 */
final class FileAnnotated extends ExplorerEvent
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $previousTags
     */
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Media $media,
        public readonly string $description,
        public readonly array $tags,
        public readonly string $previousDescription,
        public readonly array $previousTags,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
