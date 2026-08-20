<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Contracts;

/**
 * Resolves the scope key and root folder for the standalone (panel-level)
 * File Explorer page, i.e. when there is no Eloquent record to hang off.
 *
 * Implementations are resolved once per request (bind the concrete class as a
 * singleton) so `rootFolderId()` may create the root folder lazily.
 */
interface FileExplorerRootResolver
{
    /**
     * Authorization / session namespace handed to FileExplorerAuthorizer.
     */
    public function scopeKey(): string;

    /**
     * Root folder id, created on first call if it does not exist yet.
     */
    public function rootFolderId(): int;
}
