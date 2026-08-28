---
title: The form field
description: FileExplorerPicker — the explorer in a modal, writing media ids into your form's state.
---

# The form field

`FileExplorerPicker` puts an explorer in a modal behind a button, as a Filament form field.

```php
use Koassi\FilamentFileExplorer\Forms\Components\FileExplorerPicker;

FileExplorerPicker::make('brochure')                    // state: ?int
FileExplorerPicker::make('attachments')->multiple()     // state: list<int>
```

Choosing writes **media ids** into the field's state: one id when the field is single, a list when it is `->multiple()`.

![The picker: the explorer in a modal, with Choose in its own toolbar](https://koassiakakpo.github.io/filament-file-explorer/assets/screenshots/file-picker.webp)

## Which library it browses

Given neither a root nor a scope key, it browses the **standalone** library — the one the panel's own explorer page opens — and creates that root on first use, exactly as visiting the page would.

```php
FileExplorerPicker::make('files')
    ->rootFolderId($record->folder_id)
    ->scopeKey('project.'.$record->id)
```

Name a root and the scope key stays `'picker'` unless you set it too. **The two describe the same library**, and naming one never makes the field infer the other: abilities are decided per scope *and* root, so a field that inferred half the pair could end up authorised against a root it is not browsing.

A root that does not exist raises `MissingRootFolder` **with the reason**, rather than a 404 that looks like the form's fault. That is a misconfiguration in your own code — the root is an argument you passed, not a route parameter — so it gets a diagnosis rather than an HTTP status.

## Restricting what may be chosen

```php
FileExplorerPicker::make('logo')->kinds('image')
FileExplorerPicker::make('attachment')->kinds(['image', 'pdf'])
```

`image`, `pdf`, `document`, `spreadsheet`, `presentation`, `archive`, `audio`, `video` — the package's own [kind vocabulary](../browsing/large-libraries.md#filtering-by-kind), which is the same closed list the toolbar's filter menu offers.

Kinds rather than mime types or extensions, because that list already has **one SQL predicate** behind it. The restriction lands in four places from that one predicate:

1. The picker's listing shows only those kinds — before the window and the counts.
2. Its filter menu offers only those kinds.
3. A pick of anything else is refused.
4. A state carrying a wrong-kind id **fails validation**.

All four decided by the same query, so they cannot disagree. The fourth is the one that matters for safety: the state is written from the browser by the field's own listener, so nothing stops a crafted request setting the state path to any id. The validation rule resolves ids through the same resolver the field draws with, so what a user reads back and what a save accepts cannot differ.

Two smaller decisions:

- **An unknown kind throws**, at render time, rather than being dropped. A typo that silently narrows nothing is a restriction that quietly does not restrict, which is the one failure mode a limit must not have.
- **Folders are never hidden by it.** A folder has no kind, and hiding them would filter you into a room with no doors.

## Choosing

The **Choose** button sits in the explorer's toolbar beside the selection count — where the selection already is — and is enabled only once a file is selected. In the modal's footer it would sit apart from the thing it acts on, and would have to mirror the selection across two components just to know whether to be enabled.

**Folders are never chosen:** a folder is where you look, not what you pick.

Whatever the field holds is drawn under the button, in the order it was chosen, each entry removable — **with its thumbnail** where there is one to draw, and the kind's icon where there is not.

That is the same `Thumbnails::drawable()` answer the explorer's own views ask, so an image shows its picture whatever became of its conversion, and a PDF shows its picture only once one was really generated. Guessing from the mime type would put a broken image where the icon belongs. The URL goes through the guarded media route with the field's own scope key, never `$media->getUrl()` — which on a public disk would hand the file to anyone holding the link, past both guards.

See [Thumbnails](../files/thumbnails.md) for which kinds produce one.

## The state's shape

Because the state is media ids, a single field fits an `unsignedBigInteger` column and a multiple one a JSON column. Whichever shape your model already holds, the field coerces it on hydration and back on save — so `->multiple()` can be added or removed without stranding the old shape.

```php
// Both of these fill correctly.
$table->unsignedBigInteger('brochure')->nullable();
$table->json('attachments')->nullable();
```

Reading the files back:

```php
$media = Media::find($record->brochure);
```

Or let the field do it — it resolves through the containment check, so an id carried over from another scope draws nothing rather than leaking a file name.

## The value is not the selection

Reopening the modal shows nothing selected, **on purpose**.

The explorer's selection is narrowed to what is on screen — that is what stops a slow round trip naming items of a folder you have left — so a chosen file whose folder is not being browsed would be dropped on the next sync. The value belongs to the field; the selection is what is on screen now.

## Two explorers on one page

The picker is why nothing in the views or the JavaScript may be addressed globally. A page can hold the explorer *and* a picker's modal, so:

- there are no global `id` attributes — inputs are reached through refs, containers through the component's own root;
- nothing calls `document.getElementById`, and a test asserts it stays that way;
- the selection belongs to the component rather than the page, so clicking in the modal does not move the page's highlight;
- keyboard shortcuts are bound on the items container rather than the window, so one explorer never hears the other's keys.

A mutation in one [announces itself to the other](../browsing/live-updates.md#explorers-on-the-same-page), which is what keeps the page behind the modal in step.

## Height

The picker's modal caps the finder at `60vh` rather than the default `70vh`, since a modal already spends height on a heading and scrolls on its own. See [Theming](../reference/theming.md#how-tall-the-finder-gets).
