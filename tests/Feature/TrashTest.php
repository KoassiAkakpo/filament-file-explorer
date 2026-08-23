<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feTrRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feTrFolder(Folder $parent, string $name): Folder
{
    return Folder::query()->create([
        'name' => $name,
        'slug' => Str::slug($name),
        'parent_id' => $parent->id,
    ]);
}

function feTrFile(Folder $folder, string $name = 'report.pdf'): Media
{
    $path = sys_get_temp_dir().'/'.uniqid('fetr_').'-'.$name;
    file_put_contents($path, 'contents');

    return $folder->addMedia($path)
        ->usingName($name)
        ->usingFileName($name)
        ->toMediaCollection(UploadRules::collection());
}

function feTrComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feTrRoot()->id,
    ]);
}

function feTrLive(Folder $folder): int
{
    return Media::query()
        ->where('collection_name', UploadRules::collection())
        ->where('model_type', (new Folder)->getMorphClass())
        ->where('model_id', $folder->id)
        ->count();
}

it('moves a file aside instead of destroying it', function (): void {
    $root = feTrRoot();
    $media = feTrFile($root);

    feTrComponent()->call('deleteSelected', [], [$media->id]);

    // The row and the file on disk both survive; only the collection changed,
    // which is what takes it out of every listing and containment check.
    expect($media->refresh()->collection_name)->toBe(Trash::collection())
        ->and(feTrLive($root))->toBe(0)
        ->and(is_file($media->getPath()))->toBeTrue();
});

it('takes a folder and its contents out of the explorer', function (): void {
    $root = feTrRoot();
    $folder = feTrFolder($root, 'Invoices');
    $nested = feTrFolder($folder, 'Archive');
    $media = feTrFile($nested, 'march.pdf');

    feTrComponent()->call('deleteSelected', [$folder->id], []);

    expect(Folder::query()->find($folder->id))->toBeNull()
        ->and(Folder::query()->find($nested->id))->toBeNull()
        ->and(Folder::withTrashed()->find($nested->id)->trashed())->toBeTrue()
        ->and($media->refresh()->collection_name)->toBe(Trash::collection());

    // Gone from the sidebar and the listing, without either having to filter.
    $component = feTrComponent();

    expect($component->instance()->sidebarTree()[0]['children'])->toBe([])
        ->and($component->instance()->listing()['total'])->toBe(0);
});

it('lists one entry per trashed thing, not one per file inside it', function (): void {
    $root = feTrRoot();
    $folder = feTrFolder($root, 'Invoices');
    feTrFile($folder, 'a.pdf');
    feTrFile($folder, 'b.pdf');
    $loose = feTrFile($root, 'loose.pdf');

    $component = feTrComponent();
    $component->call('deleteSelected', [$folder->id], [$loose->id]);

    $rows = collect($component->instance()->trashItems());

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('name')->sort()->values()->all())->toBe(['Invoices', 'loose.pdf'])
        ->and($rows->firstWhere('type', 'folder')['path'])->toBe($root->name);
});

it('puts a folder back with what went down with it', function (): void {
    $root = feTrRoot();
    $folder = feTrFolder($root, 'Invoices');
    $nested = feTrFolder($folder, 'Archive');
    $media = feTrFile($nested, 'march.pdf');

    $component = feTrComponent();
    $component->call('deleteSelected', [$folder->id], []);
    $component->call('restoreItem', 'folder', $folder->id);

    expect(Folder::query()->find($folder->id))->not->toBeNull()
        ->and(Folder::query()->find($nested->id))->not->toBeNull()
        ->and($media->refresh()->collection_name)->toBe(UploadRules::collection())
        ->and($component->instance()->trashItems())->toBe([]);
});

it('leaves a file the user had trashed on its own in the trash', function (): void {
    $root = feTrRoot();
    $folder = feTrFolder($root, 'Invoices');
    $kept = feTrFile($folder, 'kept.pdf');
    $withFolder = feTrFile($folder, 'goes-along.pdf');

    $component = feTrComponent();

    // First the file alone, then the folder around it.
    $component->call('deleteSelected', [], [$kept->id]);
    $component->call('deleteSelected', [$folder->id], []);
    $component->call('restoreItem', 'folder', $folder->id);

    expect($withFolder->refresh()->collection_name)->toBe(UploadRules::collection())
        ->and($kept->refresh()->collection_name)->toBe(Trash::collection());

    // And it is a top-level trash entry again, now that its folder is back.
    expect(collect($component->instance()->trashItems())->pluck('name')->all())->toBe(['kept.pdf']);
});

it('purges a file for good, with its confirmation', function (): void {
    $root = feTrRoot();
    $media = feTrFile($root);
    $path = $media->getPath();

    $component = feTrComponent();
    $component->call('deleteSelected', [], [$media->id]);
    $component->call('requestPurge', 'file', $media->id);

    expect($component->get('deleteRequest')['mode'])->toBe('purge');

    $component->call('confirmDelete');

    expect(Media::query()->find($media->id))->toBeNull()
        ->and(is_file($path))->toBeFalse();
});

it('empties the whole trash of the scope', function (): void {
    $root = feTrRoot();
    $folder = feTrFolder($root, 'Invoices');
    feTrFile($folder, 'a.pdf');
    $loose = feTrFile($root, 'loose.pdf');

    $component = feTrComponent();
    $component->call('deleteSelected', [$folder->id], [$loose->id]);
    $component->call('requestPurge')->call('confirmDelete');

    expect(Folder::withTrashed()->find($folder->id))->toBeNull()
        ->and(Media::query()->count())->toBe(0);
});

it('never shows or touches the trash of another scope', function (): void {
    $foreignRoot = app(FileExplorerManager::class)->createRoot('Other', 'other');
    $foreignFolder = feTrFolder($foreignRoot, 'Theirs');
    $foreignFile = feTrFile($foreignFolder, 'secret.pdf');

    app(Trash::class)->trashFolder($foreignFolder);

    $component = feTrComponent();

    expect($component->instance()->trashItems())->toBe([]);

    $component->call('restoreItem', 'folder', $foreignFolder->id)->assertForbidden();
    feTrComponent()->call('restoreItem', 'file', $foreignFile->id)->assertForbidden();
});

it('restores under the same ability that trashed the item', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'delete' => false];
        }
    });

    $media = feTrFile(feTrRoot());
    app(Trash::class)->trashMedia($media);

    feTrComponent()->call('restoreItem', 'file', $media->id)->assertForbidden();
});

it('shows the trash instead of the folder when toggled', function (): void {
    $media = feTrFile(feTrRoot(), 'loose.pdf');

    $component = feTrComponent();
    $component->call('deleteSelected', [], [$media->id])
        ->call('toggleTrash')
        ->assertSet('showTrash', true)
        ->assertSee('loose.pdf')
        ->assertSeeHtml('wire:click="requestPurge"');

    $component->call('toggleTrash')->assertSet('showTrash', false);
});

it('deletes permanently when the trash is off', function (): void {
    config(['filament-file-explorer.trash.enabled' => false]);

    $root = feTrRoot();
    $folder = feTrFolder($root, 'Invoices');
    $media = feTrFile($folder, 'a.pdf');

    feTrComponent()->call('deleteSelected', [$folder->id], []);

    expect(Folder::withTrashed()->find($folder->id))->toBeNull()
        ->and(Media::query()->find($media->id))->toBeNull();
});

it('purges only what has sat in the trash long enough', function (): void {
    $root = feTrRoot();
    $old = feTrFolder($root, 'Old');
    $recent = feTrFolder($root, 'Recent');
    $oldFile = feTrFile($root, 'old.pdf');

    $trash = app(Trash::class);
    $trash->trashFolder($old);
    $trash->trashMedia($oldFile);

    // Backdate both, then trash something now.
    Folder::withTrashed()->whereKey($old->id)->update(['deleted_at' => Carbon::now()->subDays(60)]);
    $oldFile->refresh()->setCustomProperty('trashed_at', Carbon::now()->subDays(60)->toIso8601String())->save();
    $trash->trashFolder($recent);

    expect($trash->purgeOlderThan(30))->toBe(2)
        ->and(Folder::withTrashed()->find($old->id))->toBeNull()
        ->and(Media::query()->find($oldFile->id))->toBeNull()
        ->and(Folder::withTrashed()->find($recent->id))->not->toBeNull();
});

it('reports what the purge command did', function (): void {
    $folder = feTrFolder(feTrRoot(), 'Old');
    app(Trash::class)->trashFolder($folder);
    Folder::withTrashed()->whereKey($folder->id)->update(['deleted_at' => Carbon::now()->subDays(60)]);

    $this->artisan('file-explorer:purge-trash --days=30')
        ->expectsOutputToContain('1 item(s) purged')
        ->assertSuccessful();
});

it('says nothing to purge when the trash is disabled', function (): void {
    config(['filament-file-explorer.trash.enabled' => false]);

    $this->artisan('file-explorer:purge-trash')
        ->expectsOutputToContain('trash is disabled')
        ->assertSuccessful();
});

it('keeps a trashed file out of the media routes', function (): void {
    $media = feTrFile(feTrRoot());
    app(Trash::class)->trashMedia($media);

    // The containment check filters on the live collection, so a trashed file
    // is no longer downloadable — the trash is not a bypass.
    $this->actingAs(new User(['id' => 1]))
        ->get(route('filament-file-explorer.media.show', ['scopeKey' => 'library', 'media' => $media->id]))
        ->assertForbidden();
});

it('does not reintroduce a duplicate name when restoring', function (): void {
    $root = feTrRoot();
    $first = feTrFile($root, 'report.pdf');

    app(Trash::class)->trashMedia($first);

    // The name is free again, so a new upload takes it.
    $second = feTrFile($root, 'report.pdf');

    feTrComponent()->call('restoreItem', 'file', $first->id);

    expect($second->refresh()->file_name)->toBe('report.pdf')
        ->and($first->refresh()->file_name)->toBe('report-2.pdf')
        ->and($first->name)->toBe('report (2).pdf')
        // The file followed its new name on disk.
        ->and(is_file($first->getPath()))->toBeTrue();
});

it('stops offering what acts on the folder it is not showing', function (): void {
    $root = feTrRoot();
    feTrFolder($root, 'Docs');

    $component = feTrComponent();

    // Scoped to the toolbar's own markup. The whole translation file is embedded
    // in the root x-data, so asserting on a label against the full page finds
    // every string whether it is rendered as a control or not.
    $toolbar = fn (string $html): string => (string) Str::between($html, 'class="fe-toolbar', 'class="fe-browser');

    $folderView = $toolbar($component->html());

    expect($folderView)
        ->toContain('wire:click="createNewFolder"')
        ->toContain('x-ref="fileInput"')
        ->toContain('x-ref="folderInput"')
        ->toContain('pasteClipboard')
        ->toContain('setSort')
        ->toContain('setViewMode')
        ->toContain('fe-search');

    $trashView = $toolbar($component->call('toggleTrash')->assertSet('showTrash', true)->html());

    // A new folder and an upload landed in the folder behind the trash — which is
    // to say somewhere invisible — and read as a failure. The sort, the filters
    // and the view switcher belong to a listing the trash is not.
    expect($trashView)
        ->not->toContain('wire:click="createNewFolder"')
        ->not->toContain('x-ref="fileInput"')
        ->not->toContain('x-ref="folderInput"')
        ->not->toContain('pasteClipboard')
        ->not->toContain('setSort')
        ->not->toContain('setViewMode')
        ->not->toContain('fe-search');

    // What the trash needs stays: the way back out. Its own actions live in the
    // view rather than the toolbar, and the tests above cover them.
    expect($trashView)->toContain('wire:click="toggleTrash"');
});

it('refuses to start a new folder it could not show the input for', function (): void {
    // Not a security guard — showTrash is the client's own property. It stops a
    // wedge: the inline name input renders in the items container, which the
    // trash view does not render, so the component would sit in "creating" with
    // nothing on screen to type into or cancel.
    feTrComponent()
        ->call('toggleTrash')
        ->call('createNewFolder')
        ->assertSet('isCreatingNewFolder', false);
});

it('leaves the trash when the user navigates somewhere', function (): void {
    $root = feTrRoot();
    $docs = feTrFolder($root, 'Docs');

    $component = feTrComponent()->call('toggleTrash')->assertSet('showTrash', true);

    // The sidebar tree stays clickable while the trash is up, and this used to
    // change the folder underneath while the screen went on showing the trash —
    // a click that appeared to do nothing.
    $component->call('navigateToFolder', $docs->id)
        ->assertSet('showTrash', false)
        ->assertSet('currentFolder.id', $docs->id);
});

it('leaves the trash on the way back up and through the breadcrumb', function (): void {
    $root = feTrRoot();
    $docs = feTrFolder($root, 'Docs');

    feTrComponent()
        ->call('navigateToFolder', $docs->id)
        ->call('toggleTrash')
        ->call('navigateToParent')
        ->assertSet('showTrash', false);

    feTrComponent()
        ->call('navigateToFolder', $docs->id)
        ->call('toggleTrash')
        ->call('navigateToBreadcrumb', 0)
        ->assertSet('showTrash', false);
});

it('leaves the trash when the history is walked', function (): void {
    $root = feTrRoot();
    $docs = feTrFolder($root, 'Docs');

    feTrComponent()
        ->call('navigateToFolder', $docs->id)
        ->call('toggleTrash')
        ->call('goBack')
        ->assertSet('showTrash', false);
});
