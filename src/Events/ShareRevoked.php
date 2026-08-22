<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Koassi\FilamentFileExplorer\Models\FileShare;

/**
 * A public link was withdrawn.
 *
 * The row survives revocation, so the share is still here to inspect — what
 * changed is that it no longer serves anything.
 */
final class ShareRevoked extends ExplorerEvent
{
    public function __construct(
        string $scopeKey,
        int $rootFolderId,
        ?Authenticatable $actor,
        public readonly FileShare $share,
    ) {
        parent::__construct($scopeKey, $rootFolderId, $actor);
    }
}
