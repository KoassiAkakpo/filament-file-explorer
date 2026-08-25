<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Imagick;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Pdf as PdfGenerator;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Video as VideoGenerator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Which files get a thumbnail, and — separately — which files the explorer may
 * draw one for.
 *
 * Those are two questions and they used to be answered by the same test,
 * `str_starts_with($mime, 'image/')`, written out in four places: the grid
 * component, the column panes, the row views and the inspector. That was fine
 * while images were the only kind that could have a conversion. It stops being
 * fine the moment a PDF can.
 *
 * - `wanted()` decides whether to *register* the conversion, from the sniffed
 *   mime type and the kinds the host both asked for and can produce.
 * - `drawable()` decides whether to *render* one, and is the only question the
 *   views ask.
 *
 * PDF and video are off by default, and the reason is worth keeping. Both
 * generators are Media Library's, both pass their own `requirementsAreInstalled()`
 * without checking the thing that actually fails — Ghostscript for the PDF
 * generator, the ffmpeg *binary* for the video one — and both then throw during
 * the conversion, which runs after the media row and the original are written.
 * So a generator that is installed but not working used to take the upload down
 * with it, and the icon the explorer already draws costs nothing. Turning them on
 * is therefore a decision the host makes, and it is made twice: the kind has to
 * be listed *and* its requirements have to be there.
 */
final class Thumbnails
{
    /** The kinds a conversion can be produced for, in the order they are offered. */
    public const KINDS = ['image', 'pdf', 'video'];

    /** Memoised: asking Imagick what formats it reads is not free. */
    private static ?bool $imagickReadsPdf = null;

    /** Memoised per path, since `is_executable()` is a stat. */
    private static array $executable = [];

    public static function enabled(): bool
    {
        return (bool) config('filament-file-explorer.thumbnails.enabled', true);
    }

    public static function width(): int
    {
        return (int) config('filament-file-explorer.thumbnails.width', 320);
    }

    public static function height(): int
    {
        return (int) config('filament-file-explorer.thumbnails.height', 320);
    }

    public static function queued(): bool
    {
        return (bool) config('filament-file-explorer.thumbnails.queued', false);
    }

    /** Which page of a PDF the thumbnail is of. */
    public static function pdfPage(): int
    {
        return max(1, (int) config('filament-file-explorer.thumbnails.pdf_page', 1));
    }

    /**
     * How far into a video the frame is taken from. Not zero by default: the
     * first frame of a great many videos is black, and a black square is
     * indistinguishable from a broken thumbnail.
     */
    public static function videoSecond(): float
    {
        return max(0.0, (float) config('filament-file-explorer.thumbnails.video_second', 1));
    }

    /**
     * The kinds asked for in config, unknown names dropped.
     *
     * Dropped rather than thrown on, unlike `FileExplorerPicker::kinds()`. That
     * one is a *restriction*, and a restriction that quietly does not restrict is
     * the failure mode a limit must not have; this is an *enablement*, and a
     * typo that enables nothing errs in the safe direction. It is also read from
     * a model's conversion registration, where throwing would take down every
     * page rather than one form.
     *
     * @return list<string>
     */
    public static function requestedKinds(): array
    {
        $requested = config('filament-file-explorer.thumbnails.kinds', ['image']);
        $requested = is_array($requested) ? $requested : [$requested];

        return array_values(array_filter(
            self::KINDS,
            fn (string $kind): bool => in_array($kind, $requested, true),
        ));
    }

    /**
     * The kinds that will actually produce something: asked for *and* installed.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array_values(array_filter(self::requestedKinds(), fn (string $kind): bool => self::available($kind)));
    }

    /**
     * A representative mime type for a kind — how the generator registry is
     * asked about it.
     */
    public static function mimeFor(string $kind): ?string
    {
        return match ($kind) {
            'image' => 'image/jpeg',
            'pdf' => 'application/pdf',
            'video' => 'video/mp4',
            default => null,
        };
    }

    /**
     * Whether the tooling a kind needs is here.
     *
     * Asked of Media Library's own generator registry rather than by checking for
     * classes ourselves, because that registry is **configurable**: a host may
     * register a generator of their own — a thumbnail service, an existing
     * pipeline — and a hardcoded `class_exists(Imagick::class)` would report
     * their perfectly good setup as unavailable.
     *
     * Then extended, for the two generators the package ships whose own
     * `requirementsAreInstalled()` is known to be incomplete. Both check that a
     * *composer package* is installed and not the thing that actually fails:
     * `Pdf` never asks whether Imagick was built with a PDF delegate, and `Video`
     * never asks whether the ffmpeg binary exists. Those are precisely the two
     * ways a host ends up with a generator reporting itself ready and then
     * throwing mid-upload — the cost that kept these kinds off. Extended for
     * those two classes only, so a host's own generator is taken at its word.
     *
     * Not exhaustive, and it cannot be: a corrupt file, a timeout, or a
     * Ghostscript that is present and refuses this particular document all fail
     * at conversion time. `conversionCasualty()` is what stops those costing the
     * upload. This only stops what is knowable in advance.
     */
    public static function available(string $kind): bool
    {
        $mime = self::mimeFor($kind);

        if ($mime === null) {
            return false;
        }

        // spatie/image needs one of the two, whatever the registry says: the
        // shipped Image generator answers `true` unconditionally, so this is the
        // check about the PHP build that nothing else makes.
        if ($kind === 'image' && ! extension_loaded('gd') && ! extension_loaded('imagick')) {
            return false;
        }

        $generator = ImageGeneratorFactory::forMimeType($mime);

        if ($generator === null || ! $generator->requirementsAreInstalled()) {
            return false;
        }

        return match ($generator::class) {
            PdfGenerator::class => self::imagickReadsPdf(),
            VideoGenerator::class => self::isExecutable((string) config('media-library.ffmpeg_path', '/usr/bin/ffmpeg')),
            default => true,
        };
    }

    /**
     * Kinds the host asked for and will not get, with what is missing — the
     * answer to "why is there no thumbnail on my PDFs", which is otherwise four
     * possible answers and no way to tell them apart.
     *
     * @return array<string, string>
     */
    public static function unavailable(): array
    {
        $missing = [];

        foreach (self::requestedKinds() as $kind) {
            if (self::available($kind)) {
                continue;
            }

            $missing[$kind] = match ($kind) {
                'image' => 'needs the gd or imagick PHP extension',
                'pdf' => 'needs imagick with a PDF delegate (Ghostscript) and composer require spatie/pdf-to-image',
                'video' => 'needs composer require php-ffmpeg/php-ffmpeg and an ffmpeg binary at media-library.ffmpeg_path',
                default => 'not handled by any generator in media-library.image_generators',
            };
        }

        return $missing;
    }

    /**
     * The kind of thumbnail this media would get, or null for a file that gets
     * none.
     *
     * From the **sniffed** mime type and never from the extension, which is the
     * rule the whole feature already followed: Media Library picks its generator
     * from the extension, so a file merely *named* `.png` would be handed to GD
     * and — before the catch below existed — took the upload down with it.
     */
    public static function kindOf(Media $media): ?string
    {
        $mime = strtolower((string) $media->mime_type);

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if ($mime === 'application/pdf') {
            return 'pdf';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return null;
    }

    /**
     * Whether a conversion should be registered for this media at all.
     */
    public static function wanted(Media $media): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $kind = self::kindOf($media);

        return $kind !== null && in_array($kind, self::kinds(), true);
    }

    /**
     * Whether the explorer should draw a thumbnail for this row — the one
     * question the views ask, in place of the four copies of a mime test.
     *
     * An image is drawable whatever became of its conversion: the media route
     * falls back to the original when a conversion is missing, and an `<img>`
     * renders it. That fallback is deliberate — it is what keeps a library
     * uploaded before thumbnails existed showing its pictures — so switching the
     * views to "has a generated conversion" alone would have made every one of
     * those images an icon.
     *
     * Anything else is drawable only when a conversion really was generated. An
     * `<img>` pointed at a PDF renders nothing at all, so guessing from the mime
     * type would put a broken image where the icon used to be — on every PDF, on
     * every host whose generator is not installed.
     */
    public static function drawable(Media $media): bool
    {
        return str_starts_with(strtolower((string) $media->mime_type), 'image/')
            || $media->hasGeneratedConversion('thumbnail');
    }

    private static function imagickReadsPdf(): bool
    {
        // Imagick reads PDFs through a Ghostscript delegate compiled into it, and
        // whether that delegate is there is exactly what Media Library's own
        // check does not ask. queryFormats answers it without a shell.
        return self::$imagickReadsPdf ??= class_exists(Imagick::class)
            && Imagick::queryFormats('PDF') !== [];
    }

    private static function isExecutable(string $path): bool
    {
        return self::$executable[$path] ??= ($path !== '' && @is_executable($path));
    }
}
