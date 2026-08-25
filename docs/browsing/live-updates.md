---
title: Live updates
description: Two people on the same tree, and what closes the gap without any broadcasting to set up.
---

# Live updates

Nothing is broadcast, so two people browsing the same root see nothing of each other's uploads, renames and deletions until they navigate. `refresh.seconds` closes that gap:

```php
// config/filament-file-explorer.php
'refresh' => ['seconds' => 20],
```

```php
FilamentFileExplorerPlugin::make()->refreshEvery(20)
```

Off by default, and floored at five seconds.

## What it stands down for

The polling is driven from Alpine rather than `wire:poll`, which is what lets it decide not to fire. It skips a refresh while:

- the tab is hidden,
- an item is being dragged — a morphed DOM cancels a drag in progress,
- the user is renaming, creating a folder or uploading,
- a dialog is open.

A **"Load more" survives a refresh**: the window stays as wide as the user made it, rather than snapping back to the first page.

## What it actually adds

Most freshness is free. Livewire re-reads its models on every request and the listing is queried on every render, so anything that causes a round trip already shows the current tree.

What the refresh adds is the case nothing else covers: **the folder you are standing in has been deleted or moved out of scope by someone else.** The explorer falls back to the root rather than rendering a folder that is no longer there.

> **One known gap.** With the trash **off**, a folder *purged* under you leaves Livewire unable to restore the model at all, and the page has to be reloaded. Soft-deleted folders — which is what the trash produces, and it is on by default — restore fine and take the fallback above.

## Explorers on the same page

A mutation also announces itself to the other explorers on the page, which is what keeps a [picker](../integrating/picker.md) in a modal in step with the page behind it.

The announcement carries the component id, so a component ignores its own event — and it is never dispatched from a refresh, which is what stops two explorers refreshing each other forever.

## If you do want broadcasting

There is no hook for it and none is needed: every mutation [fires an event](../integrating/events.md), so broadcasting is a listener of yours rather than a feature of the package.

```php
Event::listen(FileExplorerEvent::class, function (FileExplorerEvent $event): void {
    broadcast(new LibraryChanged($event->scopeKey));
});
```

The scope key is on every event precisely so a listener can address the right audience.
