<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Illuminate\Support\Collection;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGenerator;

/**
 * Stands in for Media Library's PDF generator.
 *
 * Neither `spatie/pdf-to-image` nor `php-ffmpeg` is a dependency of this package,
 * and requiring either as a dev dependency would make the suite need Ghostscript
 * or an ffmpeg binary on every machine that runs it. What is worth testing here
 * is not those generators — they are Media Library's, and tested there — but
 * everything the explorer does around one: whether the conversion is registered
 * for a PDF at all, whether a generated conversion is drawn and served, and what
 * a generator that throws costs.
 *
 * The registry is config, so a stub goes in the same way a host's own generator
 * would. This one writes a real JPG, which is what makes the conversion pipeline
 * run for real: the crop, the row's generated_conversions, the media route's
 * content type.
 */
class StubPdfThumbnailGenerator extends ImageGenerator
{
    public function convert(string $file, ?Conversion $conversion = null): string
    {
        $image = imagecreatetruecolor(60, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));

        $imageFile = pathinfo($file, PATHINFO_DIRNAME).'/'.pathinfo($file, PATHINFO_FILENAME).'.jpg';
        imagejpeg($image, $imageFile);
        imagedestroy($image);

        return $imageFile;
    }

    public function requirementsAreInstalled(): bool
    {
        return extension_loaded('gd');
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
