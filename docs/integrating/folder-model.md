---
title: Your own folder model
description: Point the config at a subclass to add columns, a global scope or anything else.
---

# Your own folder model

The tree is built from `Koassi\FilamentFileExplorer\Models\Folder`. Point the config at a subclass to add columns, a global scope, a factory or anything else:

```php
// config/filament-file-explorer.php
'folders' => [
    'model' => \App\Models\Folder::class,
],
```

```php
namespace App\Models;

class Folder extends \Koassi\FilamentFileExplorer\Models\Folder
{
    // whatever you need
}
```

It has to **extend** the package's model — the media collection, the thumbnail conversion, the soft deletes and the containment walk all live on it — and a class that does not is refused with a clear message at the first query, rather than failing three layers down.

## Two things this deliberately does not change

**Media rows keep storing the package's class in `model_type`.** `getMorphClass()` answers for the base model on purpose, so switching does not orphan a library already uploaded: every row still passes the containment guard, which checks that value.

Had it followed the subclass, every existing row would have started failing that check — silently, and only for the applications that took the new feature up.

Set `morph_class` only if you are migrating from some other implementation whose rows already carry a different value:

```php
'morph_class' => 'folder',
```

**It is config, not a plugin setting.** A model cannot know which panel it is being browsed from — the same reason [the thumbnail settings](../files/thumbnails.md) are config only. Everything else that is panel-scoped is [set on the plugin](../getting-started/standalone-page.md#config-or-plugin).

## What is safe to add

A subclass is an ordinary Eloquent model, so: extra columns, casts, accessors, relations, a factory, observers, and scopes of your own.

```php
class Folder extends \Koassi\FilamentFileExplorer\Models\Folder
{
    protected static function booted(): void
    {
        static::addGlobalScope('team', fn ($query) => $query->where('team_id', auth()->user()?->current_team_id));
    }
}
```

A global scope narrows what the explorer can see, which is a legitimate way to partition a tree — but note it is **not** a substitute for the containment check. The check exists because ids arrive as user input; a scope that hides a folder from a listing does not stop an id being passed to an action. Do both.

## What the explorer needs from it

If you are overriding rather than adding, these are load-bearing:

| | |
| --- | --- |
| `parent()` / `children()` | The tree, and the containment walk |
| `registerMediaConversions()` | The `thumbnail` conversion — driven from [config](../files/thumbnails.md) |
| `SoftDeletes` | The [trash](../files/trash.md) |
| `getMorphClass()` | Containment on media rows — see above |
| `slug`, `parent_id` | Unique together; how a folder is addressed |

The relations use `static::class`, so a subclass gets its own class back from a query and from a relation alike — a mismatch there was the sharp edge of making this swappable at all.

## Reading the configured model

Never `Folder::class` directly in code that has to respect the setting. `Support\FolderModel` is the single place the class is resolved:

```php
use Koassi\FilamentFileExplorer\Support\FolderModel;

FolderModel::query()->find($id);
FolderModel::morphClass();
```

A test asserts the static forms never come back into the package, since they creep in the moment someone adds a query without thinking about it.

## The table

```php
'folders' => [
    'table' => 'file_explorer_folders',
    'max_depth' => 12,
],
```

`max_depth` bounds folder creation, folder uploads, copies and the [column view's](../browsing/views.md#columns) pane count. It is also settable per panel: `->maxFolderDepth(6)`.
