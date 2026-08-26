---
title: Record-scoped pages
description: An explorer attached to an Eloquent record, reachable from its resource.
---

# Record-scoped pages

A project, a client, a case file — anything that should own files of its own gets an explorer reachable from its resource. The scope key becomes `{model-kebab}.{id}` and the root folder is the record's own, so two records never see each other's files.

Both modes coexist. Registering the plugin does not interfere with record-scoped pages, and they use separate root folders.

## 1. Give the model a folder

```bash
php artisan filament-file-explorer:make-folder-migration projects
php artisan migrate
```

```php
use Koassi\FilamentFileExplorer\Models\Concerns\HasFileExplorer;

class Project extends Model
{
    use HasFileExplorer;
}
```

The trait adds a `folder()` relation, `ensureFileExplorerRoot()`, and the scope key. With `auto_create_root` on (the default) the root folder is created when the record is, so a new project already has somewhere to put files.

Its defaults are all overridable on the model:

```php
class Project extends Model
{
    use HasFileExplorer;

    // Which column holds the folder id — default 'folder_id'.
    public function fileExplorerFolderForeignKey(): string
    {
        return 'library_folder_id';
    }

    // What the root folder is called.
    public function fileExplorerRootFolderName(): string
    {
        return $this->name.' files';
    }

    // The scope key becomes '{prefix}.{id}' — default is the model's kebab name.
    public function fileExplorerScopeKeyPrefix(): string
    {
        return 'project';
    }
}
```

## 2. Generate the pages

```bash
php artisan filament-file-explorer:make-page ProjectResource
```

That writes two page classes into your resource's `Pages` directory, extending the package's abstract pages. Register them:

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

`--explorer` or `--list` generates just one of the two, and that is a supported way to use it: each page offers a header button linking to its companion, and the button is simply **not drawn** when the companion is not in `getPages()`. Nothing else changes.

## What the generated page is

Nothing but the two answers the explorer needs:

```php
class ManageProjectFiles extends FileExplorerPage
{
    protected static string $resource = ProjectResource::class;

    public function fileExplorerScopeKey(): string
    {
        return $this->record->fileExplorerScopeKey();
    }

    public function resolveFileExplorerRootFolderId(): int
    {
        return $this->record->ensureFileExplorerRoot()->id;
    }
}
```

The abstract parent does the rest: it resolves the pair, aborts 403 through the authorizer, and hands both values to the Livewire component. **That is the whole seam** — a third mode would mean implementing those two methods and nothing more.

Which also means the generated class is yours to change. Add a heading, a widget, an extra authorization check; it stays a Filament page.

## Authorizing a record scope

Your authorizer receives `project.4` as the scope key, so the record is right there in it:

```php
public function canAccess(string $scopeKey, int $rootFolderId): bool
{
    if (! str_starts_with($scopeKey, 'project.')) {
        return true;    // some other explorer's business
    }

    $project = Project::find((int) substr($scopeKey, strlen('project.')));

    return $project !== null && auth()->user()->can('view', $project);
}
```

See [Authorization](../integrating/authorization.md) for the rest of the contract.

## Reading a record's files from your own code

The explorer is a UI over Media Library, so a record's files are ordinary media rows on its folder tree:

```php
$root = $project->ensureFileExplorerRoot();

$root->getMedia('file-explorer');          // files at the top level

use Koassi\FilamentFileExplorer\Support\FolderTree;

$ids = app(FolderTree::class)->descendantFolderIdsIncludingRoot($root->id);
```

Use `Support\Quota` for what a record's scope holds, and `Support\Annotations` for its [descriptions and tags](../organising/tags-and-descriptions.md#from-your-own-code).
