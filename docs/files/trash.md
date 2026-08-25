---
title: Trash
description: Deleting moves things aside instead of destroying them.
---

# Trash

```php
// config/filament-file-explorer.php
'trash' => [
    'enabled' => true,                       // false deletes permanently
    'collection' => 'file-explorer-trash',
],
```

On by default. Deleting moves things aside: folders are soft-deleted, files move to a separate media collection, and the toolbar's trash button lists what is in there with where it came from and when it went — each row restorable or deletable for good.

## The two halves work differently, on purpose

**Folders use soft deletes.** A trashed folder drops out of every folder query the explorer runs, so the listing, the sidebar, the search scope and the containment walk need no extra filter at all. The exception is *where a folder sits*: a trashed folder still has to say where it came from, so parent lookups deliberately include trashed rows.

**Files move collection**, because Media Library has no soft deletes. Everything that lists or authorises media already filters on the explorer's collection, so this takes trashed files out of listings **and** out of the media routes for free — the trash is not a download bypass.

Either way the file on disk is untouched, so restoring is a database write.

## Restoring

Restoring a folder brings back what went down with it, but **leaves a file you had trashed on its own where you put it** — the two cases are distinguished, so restoring a folder does not quietly undo a deletion you made deliberately.

Restoring a file re-resolves its name, since a new upload may have taken it in the meantime.

Restore and purge require the same ability as deleting: `delete` for files, `deleteFolder` for folders. Not a new ability — reading a key an existing authorizer never answered would have denied the action for every host application, silently.

## The trash is a mode, not a folder

Two things follow, and both are visible:

- **The toolbar stops offering what acts on the folder being browsed.** A new folder and an upload would land in the folder *behind* the trash — invisible, and indistinguishable from a failure — and the sort, the filters, the view switcher and the search belong to a listing the trash is not.
- **A navigation leaves the trash.** The sidebar tree stays clickable while the trash is up, and clicking a folder there takes you to it. Without that, a click changed the folder underneath while the screen went on showing the trash — a click that appeared to do nothing.

## Nothing expires on its own

Schedule the purge if you want it to:

```php
Schedule::command('file-explorer:purge-trash --days=30')->daily();
```

```bash
php artisan file-explorer:purge-trash --days=30
```

## Trashed files still count against the quota

They sit on the disk until they are purged, and a trash that stopped counting would be a way over the cap: delete, re-upload, repeat. Same rule for [kept versions](versions.md). See [Quotas](quotas.md).

## Turning it off

`'enabled' => false` deletes permanently, as the package did before the trash existed. Worth knowing: the folder model soft-deletes either way, so with the trash off a folder delete is a **force delete** — otherwise it would leave rows nothing ever shows again and nothing ever purges.

Turning it off also re-opens the [one known live-update gap](../browsing/live-updates.md#what-it-actually-adds): a folder purged under someone browsing it leaves Livewire unable to restore the model, and the page has to be reloaded.
