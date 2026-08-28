---
title: Uploads
description: What is accepted, what happens on a name clash, and the folder upload.
---

# Uploads

Files arrive three ways — the toolbar's upload button, a drop from the desktop, or a whole folder dropped in — and all three take the same path afterwards: the same validation, the same name-clash policy, the same [quota](quotas.md) check, the same events.

Gated on the `upload` ability. A folder upload needs `mkdir` as well, since it creates the folders it fills.

## What is accepted

```php
// config/filament-file-explorer.php
'upload' => [
    'max_size_kb' => 51200,
    'allowed_extensions' => ['pdf', 'docx', 'png', /* … */],
    'allowed_mime_types' => ['application/pdf', 'image/png', /* … */],
],
```

The defaults cover every kind the shipped mime icons can render, so a file the explorer knows how to *display* is not rejected on upload.

Both lists are checked, and against the **sniffed** mime type rather than the declared one. That matters more than it sounds: the [kind filter](../browsing/large-libraries.md#filtering-by-kind), the kind sort and these rules all read `mime_type`, so a type the client chose would make all three advisory.

> **SVG is deliberately absent.** The media route serves files inline, and an SVG runs script in the panel's own origin. Adding it to both lists is one line and entirely your call — but that is what it costs.

`max_size_kb` is a promise rather than a ceiling on a stock host: four other limits sit under it and the lowest wins, and out of the box `media-library.max_file_size` (**10 MB**) is usually the one that decides. `php artisan filament-file-explorer:install` prints which — see [Ask this host what its real ceiling is](large-uploads.md#ask-this-host-what-its-real-ceiling-is).

## When the name is taken

```php
'upload' => [
    'on_conflict' => 'rename',       // rename | replace | skip
],
```

| Policy | |
| --- | --- |
| `rename` *(default)* | Keeps both, suffixing the newcomer `" (2)"` |
| `replace` | The new file takes the name; the old one becomes [a version](versions.md) |
| `skip` | Refuses the new file and says so |

`replace` used to destroy what was there — the one place in the package where something was lost without passing through the trash. With [file versions](versions.md) on, which is the default, it keeps the last three instead. Because the default policy is `rename`, a default install never takes that path at all.

## Uploading a folder

Dropping a folder from the desktop recreates its structure: the folders are created as needed and each file lands where it was.

The relative paths are sent to the server alongside the files and matched to them **by position**, which is why the paths are committed before the files are. Nothing about that is visible in normal use; it is worth knowing if you are debugging a folder upload where names and paths appear mismatched.

Bounded by `folders.max_depth`, like every other folder creation.

## What happens after the bytes land

In order:

1. The upload is validated against the rules above, on the sniffed mime type.
2. The [quota](quotas.md) is checked — and *reserved* within the request, so a ten-file upload cannot pass ten times against the same stale total.
3. The name clash policy is applied.
4. The media row is written, with the uploader recorded.
5. The [thumbnail conversion](thumbnails.md) runs, if the file's kind is one that gets one.
6. `FileUploaded` fires, per file.

Step 5 runs **after** step 4, which is why a failed thumbnail no longer costs the upload: the row exists, so the file is there, and the explorer draws its icon. `FileUploaded` fires either way — the event would otherwise be missing exactly where an upload went half-wrong.

## Errors a user can actually act on

Each refusal is counted and reported once per action rather than once per file, so dropping thirty files of which four are the wrong type gives one message and twenty-six uploads.

| | |
| --- | --- |
| Wrong type or extension | Named as a format refusal |
| Over the ceiling | Refused in the browser, **before a byte leaves**, with the size in the message |
| Over the quota | Refused before anything is written, with what is left |
| Media Library's own ceiling | Caught, rather than a stack trace — it means [`UploadLimits`](large-uploads.md) and your config disagree |
