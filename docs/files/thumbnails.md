---
title: Thumbnails
description: Images by default with nothing to install; PDF and video opt-in, and why they are opt-in.
---

# Thumbnails

Images are rendered from a `thumbnail` conversion instead of the original, so a folder of photos costs kilobytes rather than megabytes. Nothing to install: Media Library already requires `spatie/image`, which drives GD or Imagick.

```php
// config/filament-file-explorer.php
'thumbnails' => [
    'enabled' => true,
    'width' => 320,
    'height' => 320,
    'queued' => false,       // a host with no worker would never get one
    'kinds' => ['image'],    // 'pdf' and 'video' are opt-in — see below
    'pdf_page' => 1,
    'video_second' => 1,
],
```

Config only, not a plugin setting: conversions are registered on the **model**, which knows nothing about the panel it is browsed from. It is the one setting the panel-scoped precedence rules do not cover.

## Served through the media route

Like everything else, and for a specific reason: `$media->getUrl('thumbnail')` is a raw disk URL, and on a public disk that hands the file to anyone with the link, past both guards.

Going through the route means a thumbnail is subject to the same ability and containment checks as the original. The route only honours a conversion name the media **has actually generated**, so the value can never be a path smuggled in through the query string, and it falls back to the original when the conversion is missing — which is what keeps a library uploaded before thumbnails existed rendering its pictures.

To fill those in:

```bash
php artisan media-library:regenerate "Koassi\FilamentFileExplorer\Models\Folder" \
    --only=thumbnail --only-missing
```

## PDFs and videos

Add them to `kinds`, and install what they need:

| Kind | Needs |
| --- | --- |
| `pdf` | `imagick` with a PDF delegate (Ghostscript) · `composer require spatie/pdf-to-image` |
| `video` | `composer require php-ffmpeg/php-ffmpeg` · an `ffmpeg` binary at `media-library.ffmpeg_path` |

**Listing a kind is half the decision and the tooling is the other half**: a kind whose requirements are absent produces nothing, quietly. Two ways to find out:

```bash
php artisan filament-file-explorer:install     # prints what is missing
```

```php
use Koassi\FilamentFileExplorer\Support\Thumbnails;

Thumbnails::unavailable();     // ['pdf' => 'needs imagick with a PDF delegate…']
Thumbnails::kinds();           // the kinds that will actually produce something
```

### Why they are off by default

Not caution — a specific failure. Both generators are Media Library's, and **both report their requirements as installed without checking the thing that actually fails**: the PDF one never asks whether Imagick has a Ghostscript delegate, the video one never asks whether the `ffmpeg` binary exists. They then throw from inside the conversion, which runs *after* the media row and the original are written.

So a generator that is installed but not working used to take the whole upload down with it — and the icon the explorer already draws costs nothing.

That is fixed either way now: **a thumbnail that fails costs the thumbnail and nothing else.** The upload lands, the event fires, the row is there, and the explorer draws the icon. The discriminator is not the exception class — it is whether the media row is there, since a conversion only ever runs after it has been written. Nothing is swallowed blind: a genuine failure to store keeps its exception and its stack trace.

That fix was a prerequisite for offering these kinds at all, and it was a latent risk for images before it: a truncated upload that sniffs as `image/png` and that GD then refuses to decode is the same shape of failure.

### Two things to know before turning them on

- **They are slow.** A PDF page render or an `ffmpeg` seek is seconds, not milliseconds, and with `queued => false` the upload request waits for it. Turn the queue on and make sure a worker is running. One setting covers both kinds, deliberately — whoever turns these on is choosing between an upload that waits and a queue that has to be running, and that is the same decision either way.
- **A file with no thumbnail draws its icon, not a broken image.** The explorer asks whether a conversion was actually generated rather than guessing from the mime type — so a PDF gets its picture where the generator worked and its icon where it did not, on the same page if need be.

`video_second` defaults to 1 rather than 0 because a great many videos open on black, and a black square is indistinguishable from a broken thumbnail.

## Your own generator

The explorer asks Media Library's `image_generators` registry rather than checking for Imagick itself — that registry is config, so a thumbnail service of your own registered there is **taken at its word**. A hardcoded class check would have reported a perfectly good setup as unavailable.

The extra requirement checks apply only to the two generators Media Library ships, since those are the two whose own answer is known to be incomplete.

## What decides whether a thumbnail is drawn

`Support\Thumbnails` separates two questions that used to be one:

| | |
| --- | --- |
| `wanted($media)` | Whether to **register** the conversion — read only when conversions are declared |
| `drawable($media)` | Whether to **render** one — read by every view and the inspector |

`drawable()` is *is an image* **or** *has a generated conversion*, and both halves carry weight. An image with no conversion is still drawable, because the route falls back to the original and that fallback is what keeps an old library showing its pictures. Anything else is drawable only when a conversion really exists, because an `<img>` pointed at a PDF renders nothing — guessing from the mime type would put a broken image where the icon belongs, on every PDF, on every host whose generator is not installed.

`kindOf()` reads the **sniffed** mime type, never the extension. Media Library picks its generator from the extension, so a file merely *named* `.pdf` would otherwise be handed to Ghostscript.

## Coverage, honestly

**Media Library's own PDF and video generators are not covered by the test suite.** Requiring `spatie/pdf-to-image` or `php-ffmpeg` as a dev dependency would demand Ghostscript or an `ffmpeg` binary on every machine that runs the suite.

What *is* covered is everything the explorer does around a generator: stub generators go into the registry exactly the way a host's own would — including one that reports itself ready and then throws — so the availability logic, the kind gate, the drawing decision and the failure path are all tested end to end.
