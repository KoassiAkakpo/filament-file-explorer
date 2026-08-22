# Filament File Explorer

[![Latest version](https://img.shields.io/packagist/v/koassi/filament-file-explorer.svg?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![Tests](https://img.shields.io/github/actions/workflow/status/KoassiAkakpo/filament-file-explorer/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/KoassiAkakpo/filament-file-explorer/actions/workflows/tests.yml)
[![Downloads](https://img.shields.io/packagist/dt/koassi/filament-file-explorer.svg?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![PHP](https://img.shields.io/packagist/dependency-v/koassi/filament-file-explorer/php?style=flat-square)](https://packagist.org/packages/koassi/filament-file-explorer)
[![License](https://img.shields.io/packagist/l/koassi/filament-file-explorer.svg?style=flat-square)](LICENSE)

Finder-style file explorer for Filament v4/v5, powered by Spatie Media Library.

Works two ways:

- **Standalone page** *(default)* — a panel-level page in the navigation menu, backed by a single root folder. No model, no record.
- **Record-scoped pages** — an explorer attached to an Eloquent record, reachable from its resource.

## Installation

```bash
composer require koassi/filament-file-explorer

php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan migrate

php artisan filament-file-explorer:install
```

Register the plugin in your panel provider:

```php
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(FilamentFileExplorerPlugin::make());
}
```

Add the package views to your Filament theme so Tailwind picks up their classes:

```css
@source '../../../../vendor/koassi/filament-file-explorer/resources/views/**/*.blade.php';
```

```bash
npm run build
php artisan filament:assets
php artisan vendor:publish --tag=filament-file-explorer-assets
```

That's it. An **Explorer** entry appears in the navigation at `/{panel}/file-explorer`; the root folder is created on first visit.

## Configuring the standalone page

Anything in the `standalone` config block can also be set fluently on the plugin, which takes precedence and stays panel-scoped:

```php
FilamentFileExplorerPlugin::make()
    ->slug('library')                       // route: /{panel}/library
    ->scopeKey('shared-library')            // namespace handed to the authorizer
    ->rootFolder('Shared library')          // root folder name
    ->navigationLabel('Library')
    ->navigationIcon('heroicon-o-folder-open')
    ->navigationGroup('Content')
    ->navigationSort(20)
    ->withFilesNavigation()                 // also show the flat files table in the menu
    ->authorizer(\App\Support\FileExplorerAuthorizer::class)
```

Behaviour, not just chrome, is panel-scoped too:

```php
FilamentFileExplorerPlugin::make()
    ->quota(10 * 1024 ** 3)                 // bytes a scope may hold
    ->refreshEvery(20)                      // seconds between automatic refreshes
    ->defaultViewMode('columns')            // grid, columns or details
    ->maxFolderDepth(6)
    ->tableColumns(fn (array $columns) => Arr::except($columns, ['preview']))
```

Other switches: `->withoutFilesPage()`, `->withoutNavigation()`, `->withoutPages()`, `->disabled()`.

### Choosing the root folder

The scope key and root folder come from a `FileExplorerRootResolver`. Three ship with the package:

| Resolver | Behaviour |
| --- | --- |
| `GlobalRootResolver` *(default)* | One root folder shared by everyone |
| `PerUserRootResolver` | One root folder per authenticated user |
| `PerTenantRootResolver` | One root folder per Filament tenant (reuses the tenant's own root when it uses `HasFileExplorer`) |

```php
FilamentFileExplorerPlugin::make()->rootResolver(PerUserRootResolver::class)
```

Or write your own:

```php
class TeamRootResolver implements FileExplorerRootResolver
{
    public function scopeKey(): string
    {
        return 'team.'.auth()->user()->current_team_id;
    }

    public function rootFolderId(): int
    {
        return auth()->user()->currentTeam->ensureFileExplorerRoot()->id;
    }
}
```

Bind it as a singleton so the root is resolved once per request.

Two optional companion interfaces are worth implementing:

- **`ResolvesExistingRoot`** adds `existingRootFolderId(): ?int`, which must answer without writing. Filament calls `canAccess()` on every navigation render, so without it the first page of a session creates the root folder — for every user, including the ones about to be denied. The three shipped resolvers implement it. When the scope has no root yet the authorizer is asked with `0`, and the folder is created on the first real visit.
- **`ProvidesMultipleRoots`** adds `roots(): array`, and the sidebar turns into a location switcher:

```php
public function roots(): array
{
    return [
        ['id' => auth()->user()->ensureFileExplorerRoot()->id, 'name' => 'My files'],
        ['id' => $this->sharedRootId(), 'name' => 'Shared'],
    ];
}
```

The roots of one scope share its scope key, so they share one authorization decision and one ability set — roots that must be authorized apart belong in separate scopes. The explorer still browses one root at a time; the current folder and the clipboard are namespaced per root, and each location remembers where it was left. A switch is validated against `roots()`, never against an id from the request.

Anything a resolver throws that is not an abort is reported, so a missing migration or a mistyped class does not just make the page vanish from the menu.

### Replacing the pages

The packaged pages are enough for most apps. To customise them:

```bash
php artisan filament-file-explorer:make-page --standalone
```

then register the generated classes:

```php
FilamentFileExplorerPlugin::make()
    ->explorerPage(\App\Filament\Pages\FileExplorer::class)
    ->filesPage(\App\Filament\Pages\FileExplorerFiles::class)
```

## Record-scoped pages

Attach an explorer to a model instead of the panel.

1. Add a `folder_id` column and the trait:

```bash
php artisan filament-file-explorer:make-folder-migration projects
php artisan migrate
```

```php
class Project extends Model
{
    use \Koassi\FilamentFileExplorer\Models\Concerns\HasFileExplorer;
}
```

2. Generate the pages and register them:

```bash
php artisan filament-file-explorer:make-page ProjectResource
```

```php
public static function getPages(): array
{
    return [
        // ...
        'files' => Pages\ManageProjectFiles::route('/{record}/files'),
        'files-list' => Pages\ListProjectFiles::route('/{record}/files-list'),
    ];
}
```

Both modes coexist: registering the plugin does not interfere with record-scoped pages, and they use separate root folders.

## Views

Three ways to look at a folder, remembered per scope: **icons**, **columns**, and **details** (rows with kind, size and date).

Three, not five: `list` and `table` were variations on `details` with a column dropped, so they offered three ways to look at the same rows and nothing to choose between them. A stored preference naming one of them falls back to the default, which is what an unknown mode has always done.

**Columns** is the Finder's cascading browser: one pane per level of the path, the contents of the folder you are in on the right, and the trail through the tree marked in the panes behind it. Clicking a folder in any pane moves there and drops the panes beyond; clicking a file in a pane behind the last one moves there and selects it.

Two things about it are deliberate:

- **Left and right walk the panes.** Right descends into the selected folder and lands on the first entry of the pane it opens, so you can keep descending; left walks back out with the folder you just left selected. Up and down stay inside the pane, and with shift held every arrow extends the selection there instead of navigating.
- **Images show their thumbnail**, through the media route like everywhere else, at exactly the size of the icon it replaces so panes of photos and panes of documents line up on the same edge.
- **Only the last pane holds selectable items.** The panes behind it are navigation, and carry none of the `data-fe-type` / `data-id` attributes the selection layer reads — so shift-ranges and the marquee operate on the folder being browsed, exactly as in every other view. Folders in *any* pane are drop targets, though: a destination you can see is a destination you can drop on.
- **Searching falls back to the flat result list.** A search answers with matches from the whole scope, which is not a path; drawing it as one would be a lie about where the results are.

It costs one listing query per level of the path, which `folders.max_depth` bounds, and every pane is windowed in SQL like the main listing — a pane over a folder holding thousands of files is the same problem the listing already solves.

> The `table` view used to be labelled **Columns**, which is the Finder's name for the cascading browser and not what it renders. It is now labelled **Table**, and **Columns** means the browser.

## Large libraries

The explorer renders `listing.per_page` items at a time (100 by default), folders first, with a “Load more” control for the rest. Sorting, filtering and windowing all happen in SQL, so a folder holding thousands of files stays responsive.

Narrow a folder to one **kind** — images, PDF, documents, spreadsheets, presentations, archives, audio, video — from the funnel in the toolbar. The entry toggles, so choosing the active kind again clears it, and a bar above the listing says which filter is on with a way out: a narrowed folder that simply looked empty would be a bug report.

Kinds are matched on the stored mime type, never on the extension. The filter has to run in SQL or the totals and “Load more” would count rows the window then dropped — and the mime type is the half worth trusting, sniffed from the bytes rather than typed by whoever uploaded. Only files are narrowed: a folder has no kind, and hiding folders would filter you into a room with no doors. The filter is not remembered across mounts, unlike the view and the sort — how you like to look at a library is a preference, what you are looking for right now is not.

Sort by **name**, **date**, **kind** or **size**, ascending or descending. Size sorts the files; a folder has no size of its own — the only honest one would be the recursive weight of its subtree, which is a query per folder or a column to keep in step with every upload, move and delete — so it falls back to its name, as it already did for kind. Folders fill the window first whatever the sort, so they are never interleaved with the files they would be compared against.

```php
// config/filament-file-explorer.php
'listing' => ['per_page' => 100],
```

## Thumbnails

Images are rendered from a `thumbnail` conversion instead of the original, so a folder of photos costs kilobytes rather than megabytes. Nothing to install: Media Library already requires `spatie/image`, which drives GD or Imagick.

```php
'thumbnails' => [
    'enabled' => true,
    'width' => 320,
    'height' => 320,
    'queued' => false,   // a host with no worker would never get one
],
```

Thumbnails go out through the media route like everything else, so they are subject to the same ability and containment checks — and a file uploaded before this existed keeps rendering, because the route serves the original when the conversion is missing. To fill those in:

```bash
php artisan media-library:regenerate "Koassi\FilamentFileExplorer\Models\Folder" --only=thumbnail --only-missing
```

## Where files land

The package never picks a disk or a path: uploads go to `toMediaCollection()` with no disk, so everything is Media Library's own configuration — `media-library.disk_name` for the disk, its `PathGenerator` for the layout. By default that means `{disk}/{media_id}/file.png`, one directory per file at the root of the disk, mixed in with whatever else the application stores there.

To gather the explorer's files under a single directory, point Media Library's `custom_path_generators` at the `Folder` model:

```php
namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

class FileExplorerPathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        return 'file-explorer/'.$media->getKey();
    }
}
```

```php
// config/media-library.php
'custom_path_generators' => [
    \Koassi\FilamentFileExplorer\Models\Folder::class => \App\Support\FileExplorerPathGenerator::class,
],
```

```
storage/app/public/file-explorer/12/photo.png
storage/app/public/file-explorer/12/conversions/photo-thumbnail.jpg
```

Overriding only `getBasePath()` keeps `conversions/` and `responsive-images/` where Media Library expects them, and keying the generator to the model leaves the rest of the application's media untouched. Nothing in the explorer needs to know: paths are resolved through `getPath()` / `getPathRelativeToRoot()`, and the trash only ever changes a file's name and collection, never its directory. If you set `morph_class`, key the config on that value rather than on `Folder::class` unless it is in the morph map.

`media-library.prefix` does the same thing in one line, but for *every* media in the application — fine when the explorer is the only thing using Media Library.

Either way the path is computed, never stored, so the change is retroactive: files already uploaded stay where they are and the new path will not find them. Move them once before switching, or after, in a maintenance command.

Keeping the files private needs no more than a disk that is not `public` — `MEDIA_DISK`, or `media-library.disk_name`. Nothing in the explorer calls `getUrl()`: downloads, previews, thumbnails and zips all go out through the media route, behind the ability and containment checks, so a private disk costs no functionality. Keep the driver `local` rather than remote and the route still answers with a `BinaryFileResponse`, which is what gives video seeking and resumable downloads.

## Tags and descriptions

Folders and files can carry a **description** and any number of **tags**, both written in the Get Info inspector — the panel that already follows the selection, and so the only place on screen that knows which single item you mean.

Tags belong to a scope. A tag nothing carries any more is deleted, which is why there is no tag management screen: the list of tags *is* the list in use, and the filter menu can never offer a word that matches nothing.

Both are **searchable**. The search box matches names, descriptions and tag names in one query, so a note you wrote is a note you can find again.

Tags are also a filter, beside the kind filter in the toolbar. It narrows folders as well as files — a folder can be tagged, and hiding folders would hide exactly what you tagged. Across the listing a tag shows as a coloured dot, from a closed palette of seven; the colour is stored as a name, so an unknown one draws a neutral dot rather than reaching the page.

```php
// config/filament-file-explorer.php
'annotations' => [
    'enabled' => true,

    'descriptions_table' => 'file_explorer_descriptions',
    'tags_table' => 'file_explorer_tags',
    'tag_items_table' => 'file_explorer_tag_items',
],
```

Gated on the `annotate` ability, which follows `rename` for any authorizer that does not answer it: both change what an item is called rather than what it holds.

Read or write them from your own code through `Support\Annotations`:

```php
use Koassi\FilamentFileExplorer\Support\Annotations;

$annotations = app(Annotations::class);

$annotations->setDescription(Annotations::FILE, $media->id, 'The signed lease');
$annotations->attach('library', Annotations::FILE, $media->id, 'Urgent', 'red');

$annotations->description(Annotations::FOLDER, $folder->id);
$annotations->tags(Annotations::FOLDER, $folder->id);
$annotations->available('library');   // the scope's whole vocabulary, with counts
```

`'folder'` and `'file'` rather than model classes, deliberately: the folder model is swappable and media rows belong to Media Library's own model, so a class name stored here would either break on a swap or need the same care `getMorphClass()` needs.

### Why not `custom_properties`

Media Library hands you a JSON column for free, and it was the obvious place — but the search has to run in SQL, and querying inside a JSON column is written differently on sqlite, MySQL and Postgres. Everything else in the listing is filtered and sorted in the database precisely so a folder of thousands of files stays usable; an annotation the search could not reach would be a note nobody finds again. Folders have no `custom_properties` at all, so that route also meant two mechanisms for one feature.

## Quotas

`quota.bytes` (null by default) caps what a scope may hold. The cap is per root folder, so with the per-user or per-tenant resolver each user or tenant gets an allowance of that size, and the sidebar shows the usage — amber past 85%, red when full.

Uploads and copies that would go over are refused before anything is written, and the reason is reported. Replacing a file only counts the difference. Trashed files still count: they sit on the disk until they are purged, and a trash that stopped counting would be a way over the cap.

## Who added a file

The uploader is recorded on every upload and shown in the inspector and in the files table. It is resolved from the model each time rather than stored as a name, so a renamed account does not leave stale names behind, and memoised per request: a page costs one query per distinct uploader. When the account is gone, the id is still shown.

## Several sessions on the same tree

Nothing is broadcast, so two people browsing the same root see nothing of each other until they navigate. `refresh.seconds` (or `->refreshEvery()`) closes that: off by default, floored at five seconds.

The polling is driven from Alpine rather than `wire:poll`, so it stands down while the tab is hidden, while an item is being dragged, and while the user is renaming, creating a folder, uploading or reading a dialog. A "Load more" survives a refresh. If the folder you are standing in is deleted by someone else, the explorer falls back to the root instead of rendering a folder that is no longer there — with the trash off, a folder *purged* under you leaves Livewire unable to restore the model at all, and the page has to be reloaded.

Mutations also announce themselves to the other explorers on the same page, which is what keeps a `FileExplorerPicker` in a modal in step with the page behind it.

## Trash

Deleting moves things aside instead of destroying them. Folders are soft-deleted, files move to a separate media collection, and the toolbar's trash button lists what is in there with where it came from and when it went — each row restorable or deletable for good.

```php
// config/filament-file-explorer.php
'trash' => [
    'enabled' => true,                       // false deletes permanently, as before
    'collection' => 'file-explorer-trash',
],
```

Restoring a folder brings back what went down with it, but leaves a file you had trashed on its own where you put it. Restore and purge require the same ability as deleting (`delete` for files, `deleteFolder` for folders).

Nothing expires on its own. Schedule the purge if you want it to:

```php
Schedule::command('file-explorer:purge-trash --days=30')->daily();
```

Upgrading an existing install adds a `deleted_at` column to the folders table, so run `php artisan migrate`.

## Keyboard

The listing is a `listbox`: it takes focus, and the items are options.

| Keys | Action |
| --- | --- |
| Arrows | Move the selection (rows and grid) |
| Shift + click / Shift + arrows | Extend the selection to a range |
| Ctrl/Cmd + click | Add or remove one item |
| Ctrl/Cmd + A | Select everything on screen |
| Enter | Open the selected folder, or preview the selected file |
| F2 | Rename |
| Ctrl/Cmd + C / X / V | Copy, cut, paste |
| Delete / Backspace | Delete the selection |
| Escape | Close the context menu, cancel a rename |

Shortcuts only fire while the listing has focus, never while typing in the search or rename field, and each one still goes through its ability check. Downloading a multi-item selection produces a single archive (`selection/zip`, capped at 500 items).

## Touch

The finder is a mouse layout, and a finger breaks it in one specific way: the drag arms after five pixels of movement, which is also how far a finger travels when it means to scroll. On a tablet, dragging a folder to scroll it moved the folder.

So the two pointers get different gestures.

| Gesture | Mouse | Touch |
| --- | --- | --- |
| Select | click | tap |
| Open | double-click | double-tap |
| Context menu | right-click | hold |
| Drag to move or copy | press and move | — |
| Marquee select | press empty space and move | — |

A touch **never** arms a drag: it arms a hold, and moving cancels the hold and leaves the browser to scroll. Moving items is still fully reachable — the hold opens the context menu, where cut, navigate and paste do what the drag would have.

A hold on empty space opens the folder's own menu, the counterpart of right-clicking the background.

Double-tap works because the items container is `touch-action: manipulation`, which keeps pan and pinch and drops double-tap-to-zoom. iOS's own long-press callout is suppressed on items so it does not open over ours, and controls grow under `@media (pointer: coarse)` to a real hit target.

The marquee is untouched, and deliberately: it is bound to `mousedown`, so a finger never starts one. A finger dragging across empty space should scroll.

## Preview and confirmation

Double-clicking a file (or pressing Enter, or picking *Open* in the context menu) opens it in a lightbox: images, video, audio, PDFs and text render inline, anything else offers a download. Left and right arrows walk through the files of the current listing. Previewing requires the `download` ability, since it streams the same bytes as a download.

PDFs and text files render in a same-origin `<iframe>`, so an app that sends `X-Frame-Options: DENY` needs `SAMEORIGIN` (or a `frame-ancestors 'self'` CSP) for that part of the preview to show.

Deleting asks first, in a dialog that names what is about to go, counts what a folder takes with it, and lists the items the authorizer refuses to delete — those are kept, and the rest still goes.

## Authorization

Implement `FileExplorerAuthorizer` to control access and per-ability permissions. `$scopeKey` tells you which explorer is being accessed — the configured scope key for the standalone page, `{model-kebab}.{id}` for a record page.

```bash
php artisan filament-file-explorer:make-authorizer
```

```php
FilamentFileExplorerPlugin::make()->authorizer(\App\Support\FileExplorerAuthorizer::class)
```

`abilities()` returns eleven keys, and one you leave out counts as a denial. But the set can grow: nothing reads your array directly — `Support\Abilities` does, and an ability the explorer gains later that your implementation does not answer **inherits the one it is a variation of** rather than being read as a refusal. `share` follows `download`, so an authorizer written today keeps working and keeps meaning what you meant by it. Answer the new key yourself to have the last word:

```php
public function abilities(string $scopeKey, int $rootFolderId): array
{
    return [
        // … the eleven
        'share' => false,   // explicit: no sharing even though download is allowed
    ];
}
```

Nothing ever defaults to allowed — an ability nobody declared is refused. Your own extra keys are kept, so you can read them for actions of your own.

The media routes (`show`, `zip-media`, `zip-folder`) never trust the scope key in their own URL: they resolve the scope's root folder through the standalone resolver, or from the roots the current session has legitimately opened, and then require the media to sit under it. A media link is therefore only served to someone whose session has a claim to that scope — a URL kept from an expired session returns 403 until the explorer page is opened again.

## Your own folder model

The tree is built from `Koassi\FilamentFileExplorer\Models\Folder`. Point the config at a subclass to add columns, a global scope, a factory or anything else:

```php
// config/filament-file-explorer.php
'folders' => [
    'model' => \App\Models\Folder::class,
],
```

```php
class Folder extends \Koassi\FilamentFileExplorer\Models\Folder
{
    // whatever you need
}
```

It has to extend the package's model — the media collection, the thumbnail conversion, the soft deletes and the containment walk all live on it — and a class that does not is refused with a clear message at the first query rather than failing three layers down.

Two things this deliberately does *not* change:

- **Media rows keep storing the package's class in `model_type`.** `getMorphClass()` answers for the base model on purpose, so switching does not orphan a library already uploaded: every row still passes the containment guard, which checks that value. Set `morph_class` only if you are migrating from some other implementation.
- **Config, not a plugin setting.** A model cannot know which panel it is being browsed from — the same reason the thumbnail conversion is config only.

## Share links

Right-click a file and **Share** gives it a link that works with no account and no session — which is the point: it is how you send a file to someone outside the panel. The dialog shows the link, when it expires, how many times it has been opened, and a button to stop sharing.

```php
// config/filament-file-explorer.php
'share' => [
    'enabled' => true,
    'default_ttl_days' => 7,
    'max_ttl_days' => 30,   // a ceiling, not a default; null allows links that never expire
],
```

Gated on the `share` ability, which **follows `download`** for any authorizer that does not answer it — so an authorizer written before this existed keeps meaning what its author meant, and one that refuses downloads refuses sharing too.

### How it holds up without a session

The package's two guards cannot both run on a request with no authenticated user, so they are split in time:

- **The ability is checked when the link is made**, and that decision is what the row records. There is nobody to ask afterwards.
- **Containment is checked on every request**, against the root stored on the row — never against anything derived from the media, which would only prove the file sits under its own ancestor.

That split is what makes a link die on its own, with nothing to keep in step: a file **moved out of the scope it was shared from** fails the containment walk, a **trashed** file leaves the explorer's collection and fails the same check, and a **deleted** file has no row. Revoking is a database write and takes effect immediately.

The route takes the token and nothing else — no scope key, no media id, no conversion name — so a link cannot be edited into a link for another file or another rendition. A bad token, an expired one, a revoked one and a file that has gone all answer 404 alike; telling them apart would say whether a token was ever real.

Links are stored rather than signed, because revoking is the half of sharing that matters. Revoking keeps the row, so a withdrawn share is still there to inspect — and `FileShared` / `ShareRevoked` are fired for both, like every other mutation.

Upgrading an existing install adds the shares table, so run `php artisan migrate`.

## Events

Every mutation fires an event, so an application can keep an audit trail, notify, scan, index or replicate without extending the component.

```php
use Koassi\FilamentFileExplorer\Contracts\FileExplorerEvent;
use Koassi\FilamentFileExplorer\Events\FileUploaded;

// One listener for everything that happens in the explorer.
Event::listen(FileExplorerEvent::class, function (FileExplorerEvent $event): void {
    Log::info($event::class, ['scope' => $event->scopeKey]);
});

// Or one kind at a time.
Event::listen(FileUploaded::class, ScanUpload::class);
```

`FileExplorerEvent` is an **interface**, not a shared base class, because Laravel's dispatcher resolves listeners for the interfaces an event implements but not for its parent classes — so listening to it really does catch all of them.

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

All of them carry `scopeKey`, `rootFolderId` and `actor` (nullable — a console command has none). The scope is on every event because it is the only way to tell two explorers apart: the same tree is reachable from a page and from a picker in a modal, and the per-user and per-tenant resolvers run the same code for every scope.

Four things worth knowing about the shape:

- **The deletions carry a snapshot, not a model.** By the time a listener runs the row is gone and the bytes are off the disk — which is also why `size` is on `FileDeleted`, as the last moment it can be known.
- **`FileUploaded` fires per file**, so a multi-file upload fires several times, and an upload that replaces an existing file fires `FileDeleted` for what it destroyed.
- **`FolderCopied` fires once** for the folder that was asked for, never per descendant — but each file the copy created does fire `FileCopied`, because every new file row is a new object to index or scan.
- **An upload whose thumbnail failed still fires.** `CouldNotLoadImage` is thrown after the row and the original are written, so the file is there; the event would otherwise be missing exactly where an upload went half-wrong.

## Assets

There is no build step: `php artisan filament:assets` publishes the package's JS and CSS straight from its sources. Re-run it after upgrading, as you would for any Filament plugin — and keep the `@source` line from the installation steps so your theme still sees the Tailwind classes the views use.

## Testing

```bash
composer install
./vendor/bin/pest                     # PHP suite
node --test "tests/Js/*.test.mjs"     # selection and keyboard logic
```

The JS tests need nothing installed: they run the shipped script in a `node:vm` context with a stubbed Alpine and DOM.

Both suites run in CI on every push and pull request, on PHP 8.3 and 8.4. **8.3 is the floor for the test suite, not for the package** — the explorer itself runs on PHP 8.2, but Pest and Testbench both require 8.3, so there is no toolchain to run the suite with below it.

> Provider order matters when booting Filament under Testbench: `Filament\Support\SupportServiceProvider` binds Livewire's `DataStore` mechanism non-shared, so it must register **before** `LivewireServiceProvider`. See `tests/TestCase.php`.

## Roadmap

The package is on `0.x`, which is the only window in which the public API can still change. [ROADMAP.md](ROADMAP.md) records what had to be settled before it freezes at 1.0.0 — an ability set that can grow, a swappable folder model, selection state per component, domain events — all of which have shipped, along with the features that were planned alongside them. What remains is additive.

## License

MIT — see [LICENSE](LICENSE).
