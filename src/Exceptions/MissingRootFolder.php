<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Exceptions;

use RuntimeException;

/**
 * The explorer was mounted on a root folder that does not exist.
 *
 * It used to be a `findOrFail`, and so a **404** — which is the wrong answer
 * twice over. The root is not a route parameter a visitor typed: it is an
 * argument the host's own code passed, so a missing one is a misconfiguration
 * rather than a page that is not there. And "No query results for model
 * [Folder] 0" on a create page names neither the field that did it nor the
 * thing to fix, which is how a picker added to a form read as the *form* being
 * broken.
 */
class MissingRootFolder extends RuntimeException
{
    public static function for(string $scopeKey, int $rootFolderId): self
    {
        if ($rootFolderId <= 0) {
            return new self(
                'The file explorer was mounted without a root folder (scope "'.$scopeKey.'"). '
                .'A FileExplorerPicker needs one: either leave it unset to browse the standalone '
                .'library, or name the folder with ->rootFolderId($id). A record-scoped page gets '
                .'it from the record — check the record has a folder_id.'
            );
        }

        return new self(
            'The file explorer was mounted on folder #'.$rootFolderId.' (scope "'.$scopeKey.'"), '
            .'which does not exist. It may have been deleted for good, or belong to another '
            .'installation of the folders table.'
        );
    }
}
