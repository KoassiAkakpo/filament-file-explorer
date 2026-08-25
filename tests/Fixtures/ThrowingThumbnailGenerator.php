<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGenerator;

/**
 * A generator that reports itself ready and then throws — which is the exact
 * shape of the failure that kept PDF and video thumbnails out of this package.
 *
 * Media Library's `Pdf` generator checks that `spatie/pdf-to-image` is installed
 * and never that Imagick has a PDF delegate; its `Video` generator checks for the
 * FFMpeg class and never for the ffmpeg binary. Both then throw from inside
 * `convert()`, which runs *after* the media row and the original are written.
 *
 * A plain RuntimeException on purpose: the point is that the exception class is
 * not the discriminator. Nothing in the explorer names it.
 */
class ThrowingThumbnailGenerator extends ImageGenerator
{
    public function convert(string $file, ?Conversion $conversion = null): ?string
    {
        throw new RuntimeException('ffmpeg not found — this is what a missing binary looks like');
    }

    public function requirementsAreInstalled(): bool
    {
        return true;
    }

    public function supportedExtensions(): Collection
    {
        return collect(['pdf']);
    }

    public function supportedMimeTypes(): Collection
    {
        return collect(['application/pdf']);
    }
}
