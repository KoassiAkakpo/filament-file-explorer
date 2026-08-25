---
title: Where files land
description: The package never picks a disk or a path — everything is Media Library's own configuration.
---

# Where files land

The package never picks a disk or a path. Uploads go to `toMediaCollection()` with **no disk argument**, so everything is Media Library's own configuration: `media-library.disk_name` for the disk, its `PathGenerator` for the layout.

By default that means `{disk}/{media_id}/file.png` — one directory per file at the root of the disk, mixed in with whatever else the application stores there.

## Gathering the explorer's files together

Point Media Library's `custom_path_generators` at the `Folder` model:

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

Overriding only `getBasePath()` keeps `conversions/` and `responsive-images/` where Media Library expects them, and keying the generator to the model leaves the rest of the application's media untouched.

Nothing in the explorer needs to know: paths are resolved through `getPath()` and `getPathRelativeToRoot()`, and the trash and the version store only ever change a file's *name* and *collection*, never its directory.

> If you have set `morph_class`, key the config on that value rather than on `Folder::class` — unless it is in the morph map.

`media-library.prefix` does the same thing in one line, but for **every** media in the application. Fine when the explorer is the only thing using Media Library.

### It is retroactive, and that cuts both ways

The path is computed, never stored. So changing the generator changes where the explorer *looks*, and files already uploaded stay where they are — the new path will not find them. Move them once, before switching or after, in a maintenance command.

## Keeping the files private

Nothing more than a disk that is not `public`:

```dotenv
MEDIA_DISK=local
```

**Nothing in the explorer calls `getUrl()`.** Downloads, previews, thumbnails and archives all go out through the media route, behind the ability and containment checks — so a private disk costs no functionality at all.

Keep the driver `local` rather than remote and the route still answers with a `BinaryFileResponse`, which is what gives video seeking and resumable downloads.

## The collection

Every explorer file lives in one media collection, `file-explorer` by default:

```php
'collection' => 'file-explorer',
```

Two others sit beside it and are deliberately *not* it — the [trash](trash.md) and the [version store](versions.md). Because they are separate collections, every listing, the flat files table and the media routes filter them out for free: a trashed file is not a download bypass, and a kept version is not a second way to download a file. Both refuse to equal the live collection, guarded rather than documented.

## Reading the files yourself

They are ordinary Media Library rows on a `Folder`:

```php
$folder->getMedia('file-explorer');
```

What you must **not** do is trust a media id that arrived from a request without proving containment. `Support\MediaScope` is the single implementation of that check, and it takes three conditions — the row's `model_type` is the folder morph class, its `collection_name` is the explorer's, and its folder sits under the root:

```php
use Koassi\FilamentFileExplorer\Support\MediaScope;

$folder = app(MediaScope::class)->folderUnderRoot($media, $rootFolderId);

if ($folder === null) {
    abort(403);
}
```

Drop either of the first two conditions and a media row of some *other* model, whose `model_id` happens to collide with an in-scope folder id, passes containment. See [Authorization](../integrating/authorization.md#containment).
