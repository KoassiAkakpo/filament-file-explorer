<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feIlRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feIlComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feIlRootId(),
    ]);
}

it('gives the surplus height to the browsing area, not to the breadcrumb', function (): void {
    // The inspector is a sibling of the whole main column, so opening it can
    // make the flex row taller than its 560px floor. With nothing growing in
    // the column that surplus landed under the breadcrumb, which then read as a
    // breadcrumb bar three times its height.
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/file-explorer.css');

    $browser = (string) preg_replace('/\s+/', ' ', (string) strstr($css, '.fe-browser {'));

    expect($browser)->toStartWith('.fe-browser { display: flex; flex: 1 1 auto; flex-direction: column;');
});

it('keeps the breadcrumb at its own height whatever is beside it', function (): void {
    $media = Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => feIlRootId(),
        'collection_name' => UploadRules::collection(),
        'name' => 'report.pdf',
        'file_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 8,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    $html = feIlComponent()->call('showInfo', 'file', $media->id)->assertOk()->html();

    // shrink-0 on the nav and flex-1 on the items container: the breadcrumb
    // never gives up its height, and the scroll area is the browser's own.
    expect($html)->toContain('<nav class="flex shrink-0 select-none items-center')
        ->toContain('relative min-h-[500px] flex-1 select-none overflow-y-auto');
});
