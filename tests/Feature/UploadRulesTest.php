<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

// A real PNG, so finfo sniffs image/png — an accepted mime type — whatever
// follows it and whatever the file is called.
function fePolyglot(string $name, string $payload): UploadedFile
{
    $image = imagecreatetruecolor(1, 1);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    // A real file, not UploadedFile::fake(): the fake answers getMimeType()
    // from the extension, which is exactly the thing being tested against.
    $path = tempnam(sys_get_temp_dir(), 'fe-polyglot-');
    file_put_contents($path, $png.$payload);

    return new UploadedFile($path, $name, null, null, true);
}

it('refuses an accepted mime type carried by a refused extension', function (string $name, string $payload): void {
    $file = fePolyglot($name, $payload);

    expect($file->getMimeType())->toBe('image/png')
        ->and(UploadRules::isAllowedUpload($file))->toBeFalse();
})->with([
    'php' => ['shell.php', '<?php system($_GET["c"]); ?>'],
    'phtml' => ['shell.phtml', '<?php system($_GET["c"]); ?>'],
    'html' => ['page.html', '<script>alert(document.cookie)</script>'],
    'svg' => ['logo.svg', '<script>alert(1)</script>'],
    'no extension' => ['README', 'anything'],
]);

it('still accepts an accepted mime type under an accepted extension', function (): void {
    expect(UploadRules::isAllowedUpload(fePolyglot('photo.png', '')))->toBeTrue();
});

// Livewire's test uploads only take a fake, and under a test suite its
// TemporaryUploadedFile answers getMimeType() with the type the fake declared
// instead of asking finfo. Declaring image/png is therefore what stands in for
// the sniff a real request gets — which, for these bytes, says image/png.
function feUploadPolyglot(string $name, string $payload): void
{
    $root = Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
    $png = file_get_contents(fePolyglot('x.png', '')->getRealPath());

    try {
        Livewire::test(FileExplorerComponent::class, [
            'scopeKey' => 'library',
            'rootFolderId' => $root->id,
        ])->set('files', [UploadedFile::fake()->createWithContent($name, $png.$payload)->mimeType('image/png')]);
    } catch (ValidationException) {
        // Rethrown on purpose by updatedFiles(); what matters is the disk.
    }
}

function feStoredFileNames(): array
{
    return Media::query()->where('collection_name', UploadRules::collection())->pluck('file_name')->all();
}

it('never stores a polyglot under its own extension', function (): void {
    feUploadPolyglot('shell.php', '<?php system($_GET["c"]); ?>');

    expect(feStoredFileNames())->toBe([]);
});

it('stores the same bytes under an accepted extension', function (): void {
    // The control: without it, the test above would pass just as well if no
    // upload ever reached the disk.
    feUploadPolyglot('photo.png', '');

    expect(feStoredFileNames())->toBe(['photo.png']);
});
