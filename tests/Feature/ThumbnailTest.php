<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Image\Exceptions\CouldNotLoadImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feThRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feThComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feThRoot()->id,
    ]);
}

/** A real 600x400 PNG, so GD has something to work with. */
function feThUpload(string $name = 'photo.png'): Media
{
    feThComponent()->set('files', [UploadedFile::fake()->image($name, 600, 400)]);

    return Media::query()->where('file_name', $name)->firstOrFail();
}

it('generates a thumbnail on upload', function (): void {
    $media = feThUpload();

    expect($media->hasGeneratedConversion('thumbnail'))->toBeTrue()
        ->and(is_file($media->getPath('thumbnail')))->toBeTrue()
        // A crop of the configured size, not the 600x400 original.
        // A crop of the configured size. Not a size comparison: a blank fake
        // PNG compresses better at 600x400 than a 320x320 crop does.
        ->and(getimagesize($media->getPath('thumbnail')))->toMatchArray([0 => 320, 1 => 320]);
});

it('honours the configured size', function (): void {
    config([
        'filament-file-explorer.thumbnails.width' => 96,
        'filament-file-explorer.thumbnails.height' => 96,
    ]);

    $media = feThUpload();

    expect(getimagesize($media->getPath('thumbnail')))->toMatchArray([0 => 96, 1 => 96]);
});

it('can be turned off', function (): void {
    config(['filament-file-explorer.thumbnails.enabled' => false]);

    expect(feThUpload()->hasGeneratedConversion('thumbnail'))->toBeFalse();
});

it('generates nothing for a file that is not an image', function (): void {
    feThComponent()->set('files', [UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4'."\n".'x')]);

    $media = Media::query()->where('file_name', 'report.pdf')->firstOrFail();

    expect($media->hasGeneratedConversion('thumbnail'))->toBeFalse();
});

it('ignores a file that is only named like an image', function (): void {
    Exceptions::fake();

    // Media Library picks its image generator from the extension, so without a
    // check on the sniffed mime type this text would be handed to GD.
    $media = feThRoot()
        ->addMedia(tap(sys_get_temp_dir().'/'.uniqid('feth_').'-note.png', fn (string $p) => file_put_contents($p, 'plain text')))
        ->usingFileName('note.png')
        ->toMediaCollection(UploadRules::collection());

    expect($media->mime_type)->not->toStartWith('image/')
        ->and($media->hasGeneratedConversion('thumbnail'))->toBeFalse();

    Exceptions::assertNothingReported();
});

it('keeps an upload whose thumbnail cannot be generated', function (): void {
    Exceptions::fake();

    // A real PNG cut short: enough of it left that the mime still sniffs as
    // image/png — so the conversion is attempted — and not enough for GD to
    // decode it. This is what an interrupted upload looks like.
    $image = imagecreatetruecolor(600, 400);
    $path = sys_get_temp_dir().'/'.uniqid('feth_').'.png';
    imagepng($image, $path);

    feThComponent()->set('files', [
        UploadedFile::fake()->createWithContent('broken.png', substr((string) file_get_contents($path), 0, 120)),
    ]);

    $media = Media::query()->where('file_name', 'broken.png')->first();

    // The row and the original are written before the conversion runs, so
    // losing the upload over a thumbnail would be absurd.
    expect($media)->not->toBeNull()
        ->and($media->hasGeneratedConversion('thumbnail'))->toBeFalse()
        ->and(is_file($media->getPath()))->toBeTrue();

    Exceptions::assertReported(CouldNotLoadImage::class);
});

it('serves the thumbnail through the media route, not the disk', function (): void {
    $media = feThUpload();

    $url = feThComponent()->instance()->mediaThumbnailUrl((int) $media->id);

    expect($url)->toContain('conversion=thumbnail')
        // getUrl() would be a raw disk URL, checked by nothing.
        ->and($url)->not->toContain('/storage/');

    $response = $this->actingAs(new User(['id' => 1]))->get($url);

    // The conversion's own type: Media Library writes a JPG thumbnail for a PNG
    // original, and serving it as image/png would be a lie the browser has to
    // sort out.
    $response->assertOk()->assertHeader('content-type', 'image/jpeg');

    expect((int) $response->headers->get('content-length'))
        ->toBe(filesize($media->getPath('thumbnail')));
});

it('guards the thumbnail exactly like the original', function (): void {
    $foreign = Folder::query()->create(['name' => 'Other', 'slug' => 'other', 'parent_id' => null]);

    $media = Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $foreign->id,
        'collection_name' => UploadRules::collection(),
        'name' => 'secret.png',
        'file_name' => 'secret.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => ['thumbnail' => true],
        'responsive_images' => [],
    ]);

    feThComponent();

    $this->actingAs(new User(['id' => 1]))
        ->get(route('filament-file-explorer.media.show', [
            'scopeKey' => 'library',
            'media' => $media->id,
            'conversion' => 'thumbnail',
        ]))
        ->assertForbidden();
});

it('falls back to the original when the conversion is missing', function (): void {
    config(['filament-file-explorer.thumbnails.enabled' => false]);

    $media = feThUpload();

    expect($media->hasGeneratedConversion('thumbnail'))->toBeFalse();

    // A library uploaded before thumbnails existed keeps rendering, rather than
    // showing a broken image until someone runs a regenerate.
    $this->actingAs(new User(['id' => 1]))
        ->get(feThComponent()->instance()->mediaThumbnailUrl((int) $media->id))
        ->assertOk();
});

it('ignores a conversion name the media does not have', function (): void {
    $media = feThUpload();

    // The name reaches getPath(), so only one already recorded in
    // generated_conversions is ever honoured.
    $this->actingAs(new User(['id' => 1]))
        ->get(route('filament-file-explorer.media.show', [
            'scopeKey' => 'library',
            'media' => $media->id,
            'conversion' => '../../../etc/passwd',
        ]))
        ->assertOk()
        ->assertHeader('content-length', (string) filesize($media->getPath()));
});

it('renders the grid thumbnail through the route', function (): void {
    feThUpload();

    feThComponent()->assertSeeHtml('conversion=thumbnail');
});
