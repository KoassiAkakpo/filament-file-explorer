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

function feDelRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feDelFolder(Folder $parent, string $name): Folder
{
    return Folder::query()->create([
        'name' => $name,
        'slug' => Str::slug($name),
        'parent_id' => $parent->id,
    ]);
}

function feDelMedia(Folder $folder, string $name = 'report.pdf', array $attributes = []): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $folder->id,
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        ...$attributes,
    ]);
}

function feDelComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feDelRoot()->id,
    ]);
}

it('summarises what a delete would remove instead of asking with a native dialog', function (): void {
    $root = feDelRoot();
    $folder = feDelFolder($root, 'Invoices');
    feDelFolder($folder, 'Archive');
    feDelMedia($folder, 'nested.pdf');
    $file = feDelMedia($root, 'loose.pdf');

    $component = feDelComponent();
    $component->call('requestDelete', [$folder->id], [$file->id]);

    $request = $component->get('deleteRequest');

    expect($request['deletable'])->toBe(2)
        ->and($request['names'])->toBe(['Invoices', 'loose.pdf'])
        // The nested folder and its file go with it: that is what makes a
        // recursive delete worth confirming.
        ->and($request['nested_count'])->toBe(2)
        ->and($request['blocked'])->toBe([]);

    // Nothing is gone until the dialog is confirmed.
    expect(Folder::query()->find($folder->id))->not->toBeNull()
        ->and(Media::query()->find($file->id))->not->toBeNull();
});

it('renders the confirmation as a dialog', function (): void {
    $file = feDelMedia(feDelRoot(), 'loose.pdf');

    $component = feDelComponent();
    $component->call('requestDelete', [], [$file->id]);

    $component->assertOk()
        ->assertSeeHtml('role="dialog"')
        ->assertSeeHtml('aria-modal="true"')
        ->assertSeeHtml('wire:click="confirmDelete"')
        ->assertSeeHtml('wire:click="cancelDelete"')
        ->assertSee('loose.pdf');
});

it('deletes only once the confirmation is accepted', function (): void {
    $file = feDelMedia(feDelRoot(), 'loose.pdf');

    $component = feDelComponent();
    $component->call('requestDelete', [], [$file->id])
        ->call('confirmDelete');

    expect(Media::query()->find($file->id))->toBeNull()
        ->and($component->get('deleteRequest'))->toBeNull();
});

it('keeps everything when the confirmation is dismissed', function (): void {
    $file = feDelMedia(feDelRoot(), 'loose.pdf');

    $component = feDelComponent();
    $component->call('requestDelete', [], [$file->id])
        ->call('cancelDelete');

    expect(Media::query()->find($file->id))->not->toBeNull()
        ->and($component->get('deleteRequest'))->toBeNull();
});

it('lists refused items with their reason and leaves them out of the count', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function mediaDeleteState(string $scopeKey, Media $media): array
        {
            return [
                ...parent::mediaDeleteState($scopeKey, $media),
                'allowed' => false,
                'reason' => 'Outside the deletion window',
            ];
        }
    });

    $root = feDelRoot();
    $folder = feDelFolder($root, 'Invoices');
    $file = feDelMedia($root, 'locked.pdf');

    $component = feDelComponent();
    $component->call('requestDelete', [$folder->id], [$file->id]);

    $request = $component->get('deleteRequest');

    expect($request['deletable'])->toBe(1)
        ->and($request['names'])->toBe(['Invoices'])
        ->and($request['blocked'])->toBe([
            ['name' => 'locked.pdf', 'reason' => 'Outside the deletion window'],
        ]);

    $component->assertSee('Outside the deletion window');
});

it('offers no delete button when nothing in the selection can go', function (): void {
    $root = feDelRoot();

    // The root folder is never deletable.
    $component = feDelComponent();
    $component->call('requestDelete', [$root->id], []);

    $request = $component->get('deleteRequest');

    expect($request['deletable'])->toBe(0)
        ->and($request['blocked'][0]['name'])->toBe($root->name);

    $component->assertOk()
        ->assertSeeHtml('wire:click="cancelDelete"')
        ->assertDontSeeHtml('wire:click="confirmDelete"');
});

it('refuses to prepare a delete without the ability', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [
                ...parent::abilities($scopeKey, $rootFolderId),
                'delete' => false,
                'deleteFolder' => false,
            ];
        }
    });

    $file = feDelMedia(feDelRoot(), 'loose.pdf');

    feDelComponent()->call('requestDelete', [], [$file->id])->assertForbidden();
});

it('refuses to touch a media row that belongs to another model', function (): void {
    $root = feDelRoot();

    // Same shape as explorer media, but owned by another model: deleteItems()
    // used to check nothing at all here, and rename only checked containment
    // when the folder lookup happened to resolve.
    $foreign = feDelMedia($root, 'payslip.pdf', ['model_type' => (new Project)->getMorphClass()]);

    feDelComponent()->call('deleteSelected', [], [$foreign->id])->assertForbidden();
    feDelComponent()->call('startRename', 'file', $foreign->id)->assertForbidden();

    expect(Media::query()->find($foreign->id))->not->toBeNull();
});

it('refuses to delete a media row from another scope', function (): void {
    $foreignRoot = app(FileExplorerManager::class)->createRoot('Other', 'other');
    $media = feDelMedia($foreignRoot, 'secret.pdf');

    feDelComponent()->call('deleteSelected', [], [$media->id])->assertForbidden();

    expect(Media::query()->find($media->id))->not->toBeNull();
});
