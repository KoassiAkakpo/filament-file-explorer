---
title: Large uploads
description: Five ceilings stack over an upload and the lowest wins. Slicing, or direct-to-storage, is what takes the request-shaped ones out of the answer.
---

# Large uploads

`upload.max_size_kb` defaults to 50 MB and, on a stock host, **none of it was reachable**. Five ceilings stack over an upload and the lowest wins:

| Setting | Default | Caps |
| --- | --- | --- |
| `filament-file-explorer.upload.max_size_kb` | 50 MB | one file |
| `media-library.max_file_size` | **10 MB** | one file |
| `livewire.temporary_file_upload.rules` | **12 MB** | one request |
| `upload_max_filesize` (php.ini) | **2 MB** | one request |
| `post_max_size` (php.ini) | **8 MB** | the whole request body |

So the honest default was 2 MB against a promise of 50 — and a file over it failed with whichever ceiling spoke first: a 422 from Livewire's own controller that the page turned into "upload failed", or nothing at all when the web server dropped the body before PHP ever saw it. Nothing on screen named a number.

`Support\UploadLimits` is the single reader of all five, and the split it makes is the whole design: **three of them cap a request, two cap a file.** Slicing takes the first three out of the answer, because no request then carries a whole file.

Two things change it, and they are alternatives rather than additions.

## Slicing

A file too big for one request is sent as several.

```php
// config/filament-file-explorer.php
'upload' => [
    'chunk' => [
        'enabled' => true,
        'size_kb' => 4096,        // an upper bound; sized down to fit post_max_size
        'ttl_minutes' => 60,      // how long an interrupted upload's bytes are kept
    ],
],
```

What is left deciding is `max_size_kb` and Media Library's — so **raise `media-library.max_file_size` too**, or it is the number that decides:

```php
// config/media-library.php
'max_file_size' => 1024 * 1024 * 200,   // 200 MB
```

Whatever the answer works out to, the browser now knows it: a file over the limit is refused **before a byte leaves**, and the message says the size.

### It engages only where it has something to cover

A file, or a batch, that will not fit in one request. Anything that already worked keeps taking exactly the path it took before — which is the point: a transport that only runs where the old one failed cannot break an upload that was fine.

**A batch matters as much as a file.** `post_max_size` caps the request *body*, so ten 1 MB files sent together broke an 8 MB limit even though none of them was close to it. That batch failed before this existed, and it looked like a problem with the files.

### It is a transport, not a second way to store a file

The last slice leaves behind **exactly the temporary file Livewire's own upload endpoint would have written** — same directory, same hashed name, same sidecar — and returns the same signed reference, which the browser then hands to Livewire. Everything after that point is the ordinary path: same validation, same conflict policy, same quota, same versioning, same events.

A route that stored the file itself would be a second write path, and a second write path is a thing to keep in step.

### What the route guarantees

`['web', 'auth']`, and the two guards are **split in time** — the same shape as [a share link](share-links.md), for the same reason:

- **The ability and the containment walk run once, when the upload opens.** So does an early quota refusal, which is what stops someone spending ten minutes uploading a file that was never going to land.
- **Each slice after that carries a token this session was given.** The token is server-issued and nothing the client sends ever reaches a filename.
- **Slices arrive in order, one at a time.** In order, the state is one counter and a wrong index is a 409 rather than a corrupted file.
- **Appending caps at the size the opening call declared** — not merely at what the installation accepts. Otherwise a client could declare a kilobyte, pass both checks, then send a gigabyte: the early refusal would be the very thing that let it through.
- **The authoritative checks are still the ones at the write.** This route refuses *early*; it does not refuse *instead*.

### Requirements and limits

- **Livewire's temporary disk has to be local.** Appending is a raw file append, and Flysystem has no append — the one case that would need it, a remote temporary disk, is already receiving the browser's bytes directly.
- **Partials live in their own directory**, never Livewire's: that one is emptied of anything older than a day, and a partial file is not a temporary upload until it is whole.
- **A sliced upload does not resume across a page reload.** Its bytes are kept for `ttl_minutes` and then cleared by the next upload that opens, but nothing on the page picks it back up.

## Direct-to-storage

The other answer: let the browser upload straight to S3, so the bytes never reach this application and `post_max_size` never applies. That is configured in **Livewire**, not here:

```php
// config/livewire.php
'temporary_file_upload' => [
    'disk' => 's3',
    'rules' => ['file', 'max:512000'],   // 500 MB
],
```

Slicing stands down entirely when the disk is remote. There is nothing to slice under, and it would only route the upload back through the server it was just taken off.

Two things in the explorer had to change for this to work at all, and both were outright breakage rather than missing features:

- **Livewire refuses `uploadMultiple` on a remote temporary disk** — it presigns one URL per upload — and the explorer called it unconditionally. So on a host set up this way the upload button **did nothing**, with an exception that bypasses the view handler. Files now go one at a time when the disk is remote.
- **`getRealPath()` on such an upload answers with an S3 key.** Media Library does `is_file()` on it, so storing a file that had in fact arrived failed with `FileDoesNotExist`. The upload is now staged into a local file first — as a stream rather than a request body, so no PHP limit applies to that hop.

### Why staging rather than `addMediaFromDisk()`

That adder reads the mime type from the object's stored `Content-Type`, which for a presigned PUT is a header **the browser chose**. The [kind filter](../browsing/large-libraries.md#filtering-by-kind), the kind sort and the [upload rules](uploads.md#what-is-accepted) all read `mime_type`, and SVG is refused on purpose — so a client-declared type would make all three advisory.

Staging keeps the type sniffed from bytes, as it always was. A file that is already local is passed through **untouched**, which is not an optimisation: the authoritative check then runs on the very same object the validator already saw, so the two answers are necessarily identical rather than merely probably so.

## Which one should you use

| | Slicing | Direct-to-storage |
| --- | --- | --- |
| Configured in | this package | Livewire |
| Bytes cross your server | yes, in slices | no |
| Needs | a local temporary disk | an S3-compatible bucket, CORS |
| Lifts `post_max_size` | yes | yes, it never applies |
| Still capped by | `max_size_kb`, `media-library.max_file_size` | the same two |

Slicing is the one that needs no infrastructure, and it is on by default. Direct-to-storage is the one for genuinely large media, where you would rather not pay for the bytes twice.

## Coverage, honestly

`tests/Feature/ChunkedUploadTest.php` drives the real slice route end to end. `tests/Feature/RemoteUploadDiskTest.php` simulates a remote disk out of the local adapter — a filesystem whose path prefix is not a directory while its stream reads still work, which is S3's shape — and covers the staging seam and the mime-sniffing guarantee.

**A real S3 round trip is not covered.** Livewire hardcodes `tmp-for-tests` as the disk under a test suite, so the component cannot be driven against a remote one. If you deploy direct-to-storage, upload one large file by hand before trusting it.
