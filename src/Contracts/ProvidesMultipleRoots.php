<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Contracts;

/**
 * A resolver that offers a scope more than one root folder — "My files" and
 * "Shared", say — which the explorer shows as a switcher above the tree.
 *
 * Optional, like ResolvesExistingRoot: FileExplorerRootResolver still returns a
 * single id, so nothing a host app has already written has to change.
 *
 * The roots of one scope share a scope key, and therefore one ability set and
 * one authorization decision. Roots that must be authorized apart belong in
 * separate scopes, not here.
 */
interface ProvidesMultipleRoots extends FileExplorerRootResolver
{
    /**
     * Roots to offer, in the order they should be shown. The first is the
     * default, rootFolderId() must return one of these ids, and every id must
     * be a folder this request is entitled to browse — the explorer trusts this
     * list to validate a switch, so it must never be built from user input.
     *
     * @return list<array{id: int, name: string}>
     */
    public function roots(): array;
}
