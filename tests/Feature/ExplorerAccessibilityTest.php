<?php

declare(strict_types=1);

use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

function feA11yRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feA11yMedia(Folder $folder): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $folder->id,
        'collection_name' => UploadRules::collection(),
        'name' => 'report.pdf',
        'file_name' => 'report.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

function feA11yComponent(): Testable
{
    $root = feA11yRoot();

    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $root->id,
    ]);
}

it('exposes the listing as a keyboard-operable listbox', function (): void {
    // The container holds the keyboard bindings rather than the window, so a
    // second explorer on the page cannot steal the shortcuts.
    feA11yComponent()
        ->assertOk()
        ->assertSeeHtml('role="listbox"')
        ->assertSeeHtml('aria-multiselectable="true"')
        ->assertSeeHtml('data-fe-items')
        ->assertSeeHtml('tabindex="0"')
        ->assertSeeHtml('@keydown="onKeydown($event)"');
});

it('marks folders and files as selectable options', function (): void {
    $root = feA11yRoot();
    Folder::query()->create(['name' => 'Docs', 'slug' => 'docs', 'parent_id' => $root->id]);
    feA11yMedia($root);

    $html = feA11yComponent()->assertOk()->html();

    // Two options, each focusable and reporting its own selection state.
    expect(substr_count($html, 'role="option"'))->toBe(2)
        ->and(substr_count($html, 'tabindex="-1"'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('$store.feSel.hasFolder')
        ->and($html)->toContain('$store.feSel.hasFile');
});

it('hands clicks to the selection store so shift can extend a range', function (): void {
    $root = feA11yRoot();
    Folder::query()->create(['name' => 'Docs', 'slug' => 'docs', 'parent_id' => $root->id]);
    feA11yMedia($root);

    $html = feA11yComponent()->assertOk()->html();

    expect($html)->toContain("\$store.feSel.click('folder'")
        ->and($html)->toContain("\$store.feSel.click('file'");
});

it('renders breadcrumbs as buttons instead of clickable spans', function (): void {
    $root = feA11yRoot();
    $child = Folder::query()->create(['name' => 'Docs', 'slug' => 'docs', 'parent_id' => $root->id]);

    $component = feA11yComponent();
    $component->call('navigateToFolder', $child->id);

    $html = $component->html();

    // A span carrying wire:click is unreachable by keyboard, so assert on the
    // element each breadcrumb is actually rendered as.
    preg_match_all('/<([a-z]+)[^>]*navigateToBreadcrumb/i', $html, $matches);

    expect($matches[1])->toBe(['button', 'button'])
        ->and($html)->toContain('aria-current="location"');
});
