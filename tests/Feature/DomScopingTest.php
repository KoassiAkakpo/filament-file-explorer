<?php

declare(strict_types=1);

use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feDsRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feDsComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feDsRoot()->id,
    ]);
}

function feDsMedia(string $name = 'report.pdf'): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => feDsRoot()->id,
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

/**
 * @return list<string>
 */
function feDsIds(string $html): array
{
    preg_match_all('/\sid="([^"{}]+)"/', $html, $matches);

    return $matches[1];
}

it('carries no id that a second explorer would collide with', function (): void {
    $folder = Folder::query()->create(['name' => 'Reports', 'slug' => 'reports', 'parent_id' => feDsRoot()->id]);
    feDsMedia();

    $html = feDsComponent()->call('navigateToFolder', $folder->id)->html();

    // A page can hold the explorer page and a FileExplorerPicker in a modal.
    // These ids used to be shared between them: clicking upload in one opened
    // the other's file dialog, and the marquee measured the wrong container.
    foreach (['fileInput', 'folderInput', 'folder-container', 'rename-input', 'new-folder-name', 'filemanager-area'] as $id) {
        expect($html)->not->toContain('id="'.$id.'"');
    }
});

it('gives two explorers on one page no id in common', function (): void {
    $media = feDsMedia();

    // The delete dialog is the only thing left that needs an id, for its
    // aria-labelledby — which resolves to the wrong heading if two explorers
    // on the page render the same one.
    $first = feDsComponent()->call('requestDelete', [], [$media->id]);
    $second = feDsComponent()->call('requestDelete', [], [$media->id]);

    $ids = [...feDsIds($first->html()), ...feDsIds($second->html())];

    expect($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2);
});

it('reaches the file inputs through the component, not the document', function (): void {
    $html = feDsComponent()->html();

    expect($html)->toContain('x-ref="fileInput"')
        ->toContain('x-ref="folderInput"')
        ->toContain('openFilePicker()')
        ->toContain('openFolderPicker()')
        // Nothing may look an element up document-wide: on a page with two
        // explorers, "the first match" is a coin toss.
        ->and($html)->not->toContain('getElementById');
});

it('marks the inputs the focus events look for', function (): void {
    $media = feDsMedia();

    feDsComponent()->call('startRename', 'file', $media->id)
        ->assertSeeHtml('data-fe-rename-input');

    feDsComponent()->call('createNewFolder')
        ->assertSeeHtml('data-fe-new-folder-input');
});

it('tells the focus events which explorer asked', function (): void {
    $media = feDsMedia();
    $component = feDsComponent();
    $id = $component->instance()->getId();

    $component->call('startRename', 'file', $media->id)
        ->assertDispatched(
            'focus-rename-input',
            fn (string $event, array $params): bool => $params['id'] === $id,
        );

    $component->call('cancelRename')
        ->call('createNewFolder')
        ->assertDispatched(
            'new-folder-created',
            fn (string $event, array $params): bool => $params['id'] === $id,
        );
});

it('keeps the items container reachable by its data attribute', function (): void {
    // The DOM contract the selection and the marquee are built on.
    feDsComponent()->assertSeeHtml('data-fe-items');
});

it('ships the assets it edits', function (): void {
    // There is no build step, so a second copy under resources/dist could only
    // drift from the source — and nothing failed when it did.
    expect(is_dir(__DIR__.'/../../resources/dist'))->toBeFalse();

    $registered = [
        ...FilamentAsset::getStyles(['Koassi/filament-file-explorer']),
        ...FilamentAsset::getScripts(['Koassi/filament-file-explorer'], withCore: false),
    ];

    expect($registered)->not->toBeEmpty();

    foreach ($registered as $asset) {
        expect($asset->getPath())->toBeReadableFile()
            ->and($asset->getPath())->not->toContain('/dist/');
    }
});
