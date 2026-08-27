<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages\Concerns;

use Koassi\FilamentFileExplorer\Models\Concerns\HasFileExplorer;
use Koassi\FilamentFileExplorer\Support\HasFileExplorerModel;

/**
 * The record's root folder, created on the first visit when it has none.
 *
 * One implementation for both record-scoped pages, which used to hold identical
 * copies of it.
 *
 * **A record that existed before the model took the trait has no folder id.**
 * `bootHasFileExplorer()` hooks `creating`, so only records made after the trait
 * was added were given a root — and adding the explorer to a model that already
 * has rows is the ordinary way this feature gets adopted. Those pages answered
 * **404**, which reads as "no such page" over a record that is plainly there,
 * and left every host writing the same one-line override of this method.
 *
 * So it creates the root instead, and the two decisions in that are:
 *
 * - **On the visit, not before.** The same reason the standalone page creates
 *   its root in `mount()` and never in `canAccess()`: Filament calls
 *   `canAccess()` on every navigation render, and writing a folder there would
 *   do it for every authenticated user on every page of the panel. This runs
 *   once, on a real request for one record.
 * - **`ensureFileExplorerRoot()`, not `createRoot()`.** It re-reads the column
 *   first and persists the id it made, so a second visit finds the folder rather
 *   than making another.
 *
 * The authorizer runs immediately after, in `mountFileExplorer()`, against the
 * id this returns. Reaching here at all means Filament's own authorization for
 * the record's page has already passed, so the exposure is one empty folder for
 * a record the visitor can see and the explorer then refuses — deliberately
 * preferred over asking the authorizer about a root that does not exist yet,
 * which would mean teaching every record-scoped authorizer a new convention to
 * answer for.
 */
trait ResolvesRecordRootFolder
{
    protected function resolveFileExplorerRootFolderIdFromRecord(mixed $record): int
    {
        /** @var HasFileExplorer $record */
        $record = HasFileExplorerModel::assert($record);

        if ($id = $record->fileExplorerRootFolderId()) {
            return $id;
        }

        // With auto-creation off the roots are the host's to assign, so this
        // page must not write one. The message is the point: a bare 404 over a
        // record that exists names neither the cause nor the fix.
        abort_unless(
            (bool) config('filament-file-explorer.auto_create_root', true),
            404,
            $record::class.' '.$record->getKey().' has no file explorer root folder, and '
                .'filament-file-explorer.auto_create_root is off. Set its '
                .$record->fileExplorerFolderForeignKey().' column, or call '
                .'$record->ensureFileExplorerRoot() when you create the record.',
        );

        return (int) $record->ensureFileExplorerRoot()->id;
    }
}
