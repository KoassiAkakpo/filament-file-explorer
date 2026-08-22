<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * A folder's description or tags changed. The file counterpart is FileAnnotated,
 * and the reasoning about what it carries is documented there.
 */
final class FolderAnnotated extends ExplorerEvent
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $previousTags
     */
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly Folder $folder,
        public readonly string $description,
        public readonly array $tags,
        public readonly string $previousDescription,
        public readonly array $previousTags,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
