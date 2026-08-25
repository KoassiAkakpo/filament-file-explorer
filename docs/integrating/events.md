---
title: Events
description: Every mutation fires one, so audit, notify, scan, index or replicate without extending the component.
---

# Events

Every mutation fires an event, so an application can keep an audit trail, notify, scan, index or replicate without extending anything.

```php
use Koassi\FilamentFileExplorer\Contracts\FileExplorerEvent;
use Koassi\FilamentFileExplorer\Events\FileUploaded;

// One listener for everything that happens in any explorer.
Event::listen(FileExplorerEvent::class, function (FileExplorerEvent $event): void {
    Log::info($event::class, ['scope' => $event->scopeKey]);
});

// Or one kind at a time.
Event::listen(FileUploaded::class, ScanUpload::class);
```

`FileExplorerEvent` is an **interface**, not a shared base class, because Laravel's dispatcher resolves listeners for the interfaces an event implements but **not** for its parent classes. Listening to it therefore really does catch all of them.

## The twenty

| Event | Payload beside the scope |
| --- | --- |
| `FileUploaded` | `media`, `folder` |
| `FolderCreated` | `folder` |
| `FileRenamed` | `media`, `previousName` |
| `FolderRenamed` | `folder`, `previousName`, `previousSlug` |
| `FileMoved` | `media`, `fromFolderId`, `toFolderId` |
| `FolderMoved` | `folder`, `fromParentId`, `toParentId` |
| `FileCopied` | `copy`, `source`, `folder` |
| `FolderCopied` | `copy`, `source` |
| `FileTrashed` | `media`, `withFolder` |
| `FolderTrashed` | `folder` |
| `FileRestored` | `media` |
| `FolderRestored` | `folder` |
| `FileDeleted` | `mediaId`, `name`, `fileName`, `folderId`, `size`, `purgedFromTrash` |
| `FolderDeleted` | `folderId`, `name`, `parentId`, `purgedFromTrash` |
| `FileAnnotated` | `media`, `description`, `tags`, `previousDescription`, `previousTags` |
| `FolderAnnotated` | `folder`, `description`, `tags`, `previousDescription`, `previousTags` |
| `FileShared` | `media`, `share` |
| `ShareRevoked` | `share` |
| `FileVersioned` | `media`, `versionedMediaId`, `lineage` |
| `FileVersionRestored` | `media`, `replacedMediaId` |

All of them carry `scopeKey`, `rootFolderId` and `actor` — nullable, since a console command has none.

**The scope is on every event** because it is the only way to tell two explorers apart: the same tree is reachable from a page and from a [picker](picker.md) in a modal, and the per-user and per-tenant resolvers run the same code for every scope.

## Five things about the shape

- **The deletions carry a snapshot, not a model.** By the time a listener runs the row is gone and the bytes are off the disk — which is also why `size` is on `FileDeleted`, as the last moment it can be known.
- **`FileUploaded` fires per file**, so a multi-file upload fires several times.
- **A replacement fires `FileVersioned`, or `FileDeleted` when [versioning](../files/versions.md) is off.** The difference is whether anything was actually destroyed — a listener watching for deletions must not be told a file was lost when its bytes are one click away.
- **`FolderCopied` fires once** for the folder that was asked for, never per descendant. But each file the copy created **does** fire `FileCopied`, because every new file row is a new object to index or scan.
- **An upload whose thumbnail failed still fires.** The conversion runs after the row and the original are written, so the file is there; the event would otherwise be missing exactly where an upload went half-wrong.

## Useful shapes

**An audit trail.** One listener, every event:

```php
Event::listen(FileExplorerEvent::class, function (FileExplorerEvent $event): void {
    AuditEntry::create([
        'action' => class_basename($event),
        'scope'  => $event->scopeKey,
        'root'   => $event->rootFolderId,
        'actor'  => $event->actor?->getAuthIdentifier(),
    ]);
});
```

**Virus scanning.** `FileUploaded` gives you the media row; delete it and fire your own notification if it fails:

```php
Event::listen(FileUploaded::class, function (FileUploaded $event): void {
    ScanFile::dispatch($event->media->id);
});
```

**A search index.** Subscribe to the uploads, renames, moves and deletions; the annotations events keep [descriptions and tags](../organising/tags-and-descriptions.md) in step, and they carry the previous values so a diff is free.

**Broadcasting.** There is no hook and none is needed — see [Live updates](../browsing/live-updates.md#if-you-do-want-broadcasting).

## Queueing a listener

Ordinary Laravel: implement `ShouldQueue`. Worth remembering that the **deletion events carry a snapshot rather than a model**, which is exactly what makes them safe to queue — a queued listener on `FileDeleted` still knows the name and size, where a serialised model would fail to restore.
