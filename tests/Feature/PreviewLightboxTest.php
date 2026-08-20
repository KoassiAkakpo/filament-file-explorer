<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\Project;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function fePvRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function fePvMedia(Folder $folder, string $name, array $attributes = []): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $folder->id,
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 2048,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        ...$attributes,
    ]);
}

function fePreviewComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => fePvRoot()->id,
    ]);
}

it('classifies what it can show inline', function (string $mime, string $kind): void {
    $media = fePvMedia(fePvRoot(), 'file.bin', ['mime_type' => $mime]);

    $component = fePreviewComponent();
    $component->call('preview', $media->id);

    expect($component->get('previewItem')['kind'])->toBe($kind);
})->with([
    ['image/png', 'image'],
    ['video/mp4', 'video'],
    ['audio/mpeg', 'audio'],
    ['application/pdf', 'frame'],
    ['text/plain', 'frame'],
    ['application/zip', 'other'],
]);

it('renders the matching element in the lightbox', function (): void {
    $root = fePvRoot();
    $image = fePvMedia($root, 'photo.png', ['mime_type' => 'image/png']);

    $component = fePreviewComponent();
    $component->call('preview', $image->id);

    $component->assertOk()
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-modal="true"')
        ->assertSeeHtml('class="fe-lightbox__image"')
        ->assertSeeHtml('wire:click="closePreview"')
        ->assertSee('photo.png');
});

it('falls back to a download when the type cannot be shown', function (): void {
    $media = fePvMedia(fePvRoot(), 'archive.zip', ['mime_type' => 'application/zip']);

    $component = fePreviewComponent();
    $component->call('preview', $media->id);

    $component->assertOk()
        ->assertSee('cannot be previewed')
        ->assertDontSeeHtml('class="fe-lightbox__image"');
});

it('walks through the files of the current listing', function (): void {
    $root = fePvRoot();
    $first = fePvMedia($root, 'a.pdf');
    $second = fePvMedia($root, 'b.pdf');
    $third = fePvMedia($root, 'c.pdf');

    $component = fePreviewComponent();
    $component->call('preview', $second->id);

    expect($component->get('previewItem')['position'])->toBe(2)
        ->and($component->get('previewItem')['total'])->toBe(3);

    $component->call('previewShift', 1);
    expect($component->get('previewItem')['id'])->toBe($third->id);

    // The window has an end: stepping past it keeps the current file.
    $component->call('previewShift', 1);
    expect($component->get('previewItem')['id'])->toBe($third->id);

    $component->call('previewShift', -1)->call('previewShift', -1);
    expect($component->get('previewItem')['id'])->toBe($first->id);

    $component->call('previewShift', -1);
    expect($component->get('previewItem')['id'])->toBe($first->id);
});

it('closes the lightbox', function (): void {
    $media = fePvMedia(fePvRoot(), 'a.pdf');

    $component = fePreviewComponent();
    $component->call('preview', $media->id)->call('closePreview');

    expect($component->get('previewItem'))->toBeNull();
});

it('needs the download ability, since the preview streams the file', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'download' => false];
        }
    });

    $media = fePvMedia(fePvRoot(), 'a.pdf');

    fePreviewComponent()->call('preview', $media->id)->assertForbidden();
});

it('refuses to preview a file outside the scope', function (): void {
    $foreignRoot = app(FileExplorerManager::class)->createRoot('Other', 'other');
    $media = fePvMedia($foreignRoot, 'secret.pdf');

    fePreviewComponent()->call('preview', $media->id)->assertForbidden();
});

it('refuses to preview a media row owned by another model', function (): void {
    $media = fePvMedia(fePvRoot(), 'payslip.pdf', ['model_type' => (new Project)->getMorphClass()]);

    fePreviewComponent()->call('preview', $media->id)->assertForbidden();
});
