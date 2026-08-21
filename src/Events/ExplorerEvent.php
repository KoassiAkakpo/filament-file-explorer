<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerEvent;

/**
 * What every explorer event carries.
 *
 * The scope key and the root folder id are on all of them because they are the
 * only way a listener can tell two explorers apart: the same tree is reachable
 * from a panel page and from a FileExplorerPicker in a modal, and with the
 * per-user or per-tenant resolver the same code runs for every scope.
 *
 * The actor is whoever was authenticated when it happened, and it is nullable:
 * a console command or a queued job legitimately has none.
 */
abstract class ExplorerEvent implements FileExplorerEvent
{
    public function __construct(
        public readonly string $scopeKey,
        public readonly int $rootFolderId,
        public readonly ?Authenticatable $actor = null,
    ) {}
}
