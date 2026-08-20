<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Contracts;

/**
 * A resolver that can answer "does this scope already have a root folder?"
 * without creating one.
 *
 * Separate from FileExplorerRootResolver on purpose: adding a method to that
 * interface would break every resolver a host app has already written against
 * it. Implement this one as well and the standalone pages stop creating a root
 * folder while rendering the navigation menu; skip it and they behave as before.
 */
interface ResolvesExistingRoot extends FileExplorerRootResolver
{
    /**
     * Root folder id if it exists already, null otherwise. Must not write.
     */
    public function existingRootFolderId(): ?int;
}
