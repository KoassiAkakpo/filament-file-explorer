<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Events\FileUploaded;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Thumbnails;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\StubPdfThumbnailGenerator;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ThrowingThumbnailGenerator;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feThKRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feThKComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feThKRootId(),
    ]);
}

/**
 * Puts a generator in the registry, the way a host would register their own —
 * which is also the only way to exercise the PDF path here, since neither
 * spatie/pdf-to-image nor php-ffmpeg is installed.
 */
function feThKGenerator(string $class): void
{
    config(['media-library.image_generators' => [Image::class, $class]]);
}

function feThKUploadPdf(string $name = 'report.pdf'): ?Media
{
    feThKComponent()->set('files', [
        UploadedFile::fake()->createWithContent($name, '%PDF-1.4'."\n".str_repeat('x', 400)),
    ]);

    return Media::query()->where('collection_name', UploadRules::collection())->latest('id')->first();
}

/* ---------------------------------------------------------- which kinds, and why */

it('asks for images and nothing else out of the box', function (): void {
    // Off by default and opt-in by name: both other generators report their
    // requirements as installed without checking the thing that actually fails,
    // and then throw mid-upload.
    expect(Thumbnails::requestedKinds())->toBe(['image'])
        ->and(Thumbnails::kinds())->toBe(['image']);
});

it('drops a kind nobody has heard of', function (): void {
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdfs', 'holograms']]);

    // Dropped rather than thrown on, unlike the picker's ->kinds(): that one is a
    // restriction, and a restriction that quietly does not restrict is the one
    // failure mode a limit must not have. This is an enablement, so a typo that
    // enables nothing errs the safe way — and it is read from a model's conversion
    // registration, where throwing would take down every page rather than a form.
    expect(Thumbnails::requestedKinds())->toBe(['image']);
});

it('keeps the order it offers them in, not the order they were written', function (): void {
    config(['filament-file-explorer.thumbnails.kinds' => ['video', 'image']]);

    expect(Thumbnails::requestedKinds())->toBe(['image', 'video']);
});

it('will not enable a kind whose tooling is absent', function (): void {
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf', 'video']]);

    // Neither spatie/pdf-to-image nor php-ffmpeg is installed here, so listing
    // the kinds is half a decision and the tooling is the other half.
    expect(Thumbnails::available('pdf'))->toBeFalse()
        ->and(Thumbnails::available('video'))->toBeFalse()
        ->and(Thumbnails::kinds())->toBe(['image']);
});

it('says what is missing, per kind', function (): void {
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf', 'video']]);

    $missing = Thumbnails::unavailable();

    // "Why are there no thumbnails on my PDFs" has four possible answers and from
    // the panel they look identical. This is the one nothing else can tell you.
    expect(array_keys($missing))->toBe(['pdf', 'video'])
        ->and($missing['pdf'])->toContain('spatie/pdf-to-image')
        ->and($missing['video'])->toContain('ffmpeg');
});

it('takes a generator the host registered at its word', function (): void {
    feThKGenerator(StubPdfThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    // Asked of Media Library's registry rather than by checking for Imagick
    // ourselves: that registry is config, and a host with a thumbnail service of
    // their own would otherwise be told their working setup is unavailable.
    expect(Thumbnails::available('pdf'))->toBeTrue()
        ->and(Thumbnails::kinds())->toBe(['image', 'pdf']);
});

it('reads the kind off the sniffed type, never the extension', function (): void {
    $liar = Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => feThKRootId(),
        'collection_name' => UploadRules::collection(),
        'name' => 'invoice.pdf',
        'file_name' => 'invoice.pdf',
        // A PDF by name, a PNG by content.
        'mime_type' => 'image/png',
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    // The rule the whole feature already followed: Media Library picks its
    // generator from the extension, so a file merely *named* .pdf would be handed
    // to Ghostscript.
    expect(Thumbnails::kindOf($liar))->toBe('image');
});

/* ------------------------------------------------------- registering the conversion */

it('registers no conversion for a pdf while pdf is not asked for', function (): void {
    feThKGenerator(StubPdfThumbnailGenerator::class);

    $media = feThKUploadPdf();

    // The generator is right there and willing. What decides is the config.
    expect($media)->not->toBeNull()
        ->and($media->hasGeneratedConversion('thumbnail'))->toBeFalse();
});

it('registers one once pdf is asked for and can be produced', function (): void {
    feThKGenerator(StubPdfThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    $media = feThKUploadPdf();

    expect($media->hasGeneratedConversion('thumbnail'))->toBeTrue();
});

it('registers none at all when thumbnails are off', function (): void {
    feThKGenerator(StubPdfThumbnailGenerator::class);
    config([
        'filament-file-explorer.thumbnails.enabled' => false,
        'filament-file-explorer.thumbnails.kinds' => ['image', 'pdf'],
    ]);

    expect(feThKUploadPdf()->hasGeneratedConversion('thumbnail'))->toBeFalse();
});

it('carries the page and the frame onto the conversion', function (): void {
    config([
        'filament-file-explorer.thumbnails.pdf_page' => 3,
        'filament-file-explorer.thumbnails.video_second' => 4.5,
    ]);

    $folder = FolderModel::query()->findOrFail(feThKRootId());
    $folder->registerMediaConversions(new Media(['mime_type' => 'image/png']));

    $conversion = $folder->mediaConversions[0];

    // Set unconditionally: the image generator ignores both, and reading them per
    // kind would be a branch that buys nothing.
    expect($conversion->getPdfPageNumber())->toBe(3)
        ->and($conversion->getExtractVideoFrameAtSecond())->toBe(4.5);
});

it('takes a video frame from a second in, not from zero', function (): void {
    // A great many videos open on black, and a black square is indistinguishable
    // from a broken thumbnail.
    expect(Thumbnails::videoSecond())->toBeGreaterThan(0.0);
});

/* --------------------------------------------------------------- drawing decision */

it('draws a thumbnail for a pdf that has one', function (): void {
    feThKGenerator(StubPdfThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    $media = feThKUploadPdf();

    expect(Thumbnails::drawable($media))->toBeTrue();

    $html = feThKComponent()->assertOk()->html();

    expect($html)->toContain('conversion=thumbnail');
});

it('draws the icon for a pdf that has none', function (): void {
    $media = feThKUploadPdf();

    // An <img> pointed at a PDF renders nothing at all, so guessing from the mime
    // type would put a broken image where the icon belongs — on every PDF, on
    // every host whose generator is not installed.
    expect(Thumbnails::drawable($media))->toBeFalse();

    expect(feThKComponent()->assertOk()->html())->not->toContain('conversion=thumbnail');
});

it('still draws an image whose conversion never happened', function (): void {
    config(['filament-file-explorer.thumbnails.enabled' => false]);

    feThKComponent()->set('files', [UploadedFile::fake()->image('photo.png', 40, 40)]);

    $media = Media::query()->where('collection_name', UploadRules::collection())->firstOrFail();

    // The media route falls back to the original when a conversion is missing,
    // and that fallback is deliberate: it is what keeps a library uploaded before
    // thumbnails existed showing its pictures. Asking only "has a generated
    // conversion" would have turned every one of them into an icon.
    expect($media->hasGeneratedConversion('thumbnail'))->toBeFalse()
        ->and(Thumbnails::drawable($media))->toBeTrue();
});

it('serves a pdf thumbnail as an image, not as a pdf', function (): void {
    feThKGenerator(StubPdfThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    $media = feThKUploadPdf();

    $response = $this->actingAs(new User(['id' => 1]))
        ->get(route('filament-file-explorer.media.show', [
            'scopeKey' => 'library',
            'media' => $media->id,
            'conversion' => 'thumbnail',
        ]))->assertSuccessful();

    // A conversion is always an image whatever the original was — served as
    // application/pdf it would not render.
    expect($response->headers->get('content-type'))->toStartWith('image/');
});

/* ------------------------------------------------ a generator that throws mid-upload */

it('keeps the upload when the thumbnail generator throws', function (): void {
    feThKGenerator(ThrowingThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    $media = feThKUploadPdf('contract.pdf');

    // This is the cost that kept these kinds off. A conversion runs *after* the
    // row and the original are written, and the catch used to name
    // CouldNotLoadImage — right while images were the only kind converted, and a
    // lost upload the moment Ghostscript or ffmpeg threw something else.
    expect($media)->not->toBeNull()
        ->and($media->name)->toBe('contract.pdf')
        ->and($media->hasGeneratedConversion('thumbnail'))->toBeFalse()
        ->and(Storage::disk('public')->exists($media->id.'/'.$media->file_name))->toBeTrue();
});

it('reports the upload even when its thumbnail failed', function (): void {
    feThKGenerator(ThrowingThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    Event::fake([FileUploaded::class]);

    feThKUploadPdf();

    // A listener that never heard would leave a hole in an audit trail exactly
    // where an upload went half-wrong.
    Event::assertDispatched(FileUploaded::class);
});

it('keeps the copy when a copy’s thumbnail generator throws', function (): void {
    feThKGenerator(StubPdfThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    $media = feThKUploadPdf();
    $target = FolderModel::query()->create([
        'name' => 'Copies', 'slug' => 'copies-'.Str::lower(Str::random(4)), 'parent_id' => feThKRootId(),
    ]);

    feThKGenerator(ThrowingThumbnailGenerator::class);

    feThKComponent()
        ->call('copySelection', null, $media->id)
        ->call('navigateToFolder', (int) $target->id)
        ->call('pasteClipboard');

    // Same rule on the copy path, which had the same narrow catch.
    expect(Media::query()->where('model_id', $target->id)->where('collection_name', UploadRules::collection())->count())
        ->toBe(1);
});

it('lets a real failure to store keep its exception', function (): void {
    // The disk does not exist, so Media Library throws *before* writing the row.
    // The probe then finds nothing, which is what tells the catch this was the
    // write failing rather than the thumbnail — and swallowing it would lose the
    // file and the reason for it at once.
    config(['media-library.disk_name' => 'no-such-disk']);

    expect(fn () => feThKUploadPdf())->toThrow('There is no filesystem disk named `no-such-disk`');

    expect(Media::query()->where('collection_name', UploadRules::collection())->count())->toBe(0);
});

it('leaves images alone whatever the pdf setting says', function (): void {
    feThKGenerator(ThrowingThumbnailGenerator::class);
    config(['filament-file-explorer.thumbnails.kinds' => ['image', 'pdf']]);

    feThKComponent()->set('files', [UploadedFile::fake()->image('photo.png', 40, 40)]);

    $media = Media::query()->where('collection_name', UploadRules::collection())->firstOrFail();

    // The throwing generator claims PDFs only, so the image takes the ordinary
    // path — turning a kind on must not put the working kind at risk.
    expect($media->hasGeneratedConversion('thumbnail'))->toBeTrue();
})->skip(! extension_loaded('gd'), 'needs GD');
