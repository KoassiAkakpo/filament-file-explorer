<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feRfRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feRfComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feRfRoot()->id,
    ]);
}

function feRfFolder(Folder $parent, string $name): Folder
{
    return Folder::query()->create(['name' => $name, 'slug' => Str::slug($name), 'parent_id' => $parent->id]);
}

function feRfMedia(Folder $folder, string $name): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $folder->id,
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
 * A component sitting inside a folder, the way a second session would be.
 */
function feRfInside(Folder $folder): Testable
{
    return feRfComponent()->call('navigateToFolder', $folder->id);
}

it('picks up what another session added', function (): void {
    $component = feRfComponent();

    feRfMedia(feRfRoot(), 'theirs.pdf');

    // Nothing more than an entry point for the poll: it is the request itself
    // that re-reads the listing.
    $component->call('refreshExplorer')->assertOk()->assertSee('theirs.pdf');
});

it('drops the memoised tree so the sidebar catches up', function (): void {
    $component = feRfComponent();

    // Warm the memo, then create a folder outside this component.
    expect($component->instance()->sidebarTree()[0]['children'])->toBe([]);

    feRfFolder(feRfRoot(), 'Theirs');

    $component->call('refreshExplorer');

    expect($component->instance()->sidebarTree()[0]['children'])->toHaveCount(1);
});

it('falls back to the root when the folder being browsed is deleted', function (): void {
    $root = feRfRoot();
    $folder = feRfFolder($root, 'Doomed');

    $component = feRfInside($folder);

    app(Trash::class)->trashFolder($folder->fresh());

    $component->call('refreshExplorer')
        ->assertOk()
        // Better than rendering a folder that is no longer there.
        ->assertSet('currentFolder.id', $root->id);
});

it('keeps a widened window instead of collapsing it', function (): void {
    config(['filament-file-explorer.listing.per_page' => 2]);

    $root = feRfRoot();

    foreach (range(1, 6) as $i) {
        feRfMedia($root, "f{$i}.pdf");
    }

    $component = feRfComponent();
    $component->call('loadMore');

    expect($component->get('perPage'))->toBe(4);

    $component->call('refreshExplorer');

    // resetListing() would send this back to 2 and undo what the user asked.
    expect($component->get('perPage'))->toBe(4);
});

it('stands down while the user is renaming', function (): void {
    $folder = feRfFolder(feRfRoot(), 'Reports');
    $component = feRfInside($folder);

    $component->call('startRename', 'folder', $folder->id);

    app(Trash::class)->trashFolder($folder->fresh());

    // Pulling the ground out from under a half-typed rename is worse than
    // being a few seconds behind.
    $component->call('refreshExplorer')
        ->assertSet('renamingId', $folder->id)
        ->assertSet('currentFolder.id', $folder->id);
});

it('stands down while a dialog is open', function (): void {
    $root = feRfRoot();
    $folder = feRfFolder($root, 'Reports');
    $media = feRfMedia($folder, 'report.pdf');

    $component = feRfInside($folder);
    $component->call('requestDelete', [], [$media->id]);

    app(Trash::class)->trashFolder($folder->fresh());

    $component->call('refreshExplorer')
        ->assertSet('currentFolder.id', $folder->id);

    expect($component->get('deleteRequest'))->not->toBeNull();
});

it('needs the browse ability to refresh', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'browse' => false];
        }
    });

    $folder = feRfFolder(feRfRoot(), 'Reports');
    $component = feRfInside($folder);

    app(Trash::class)->trashFolder($folder->fresh());

    $component->call('refreshExplorer')->assertSet('currentFolder.id', $folder->id);
});

it('announces a mutation once, with its origin', function (): void {
    $component = feRfComponent();

    $component->call('createNewFolder')
        ->set('newFolderName', 'Shared')
        ->call('saveNewFolder')
        ->assertDispatched('fe-tree-changed', function (string $event, array $params) use ($component): bool {
            return $params['scopeKey'] === 'library'
                // Without the origin, a component would answer its own event.
                && $params['origin'] === $component->instance()->getId();
        });
});

it('says nothing on a plain navigation', function (): void {
    $folder = feRfFolder(feRfRoot(), 'Reports');

    feRfComponent()
        ->call('navigateToFolder', $folder->id)
        ->assertNotDispatched('fe-tree-changed');
});

it('ignores an announcement from itself or from another scope', function (): void {
    $root = feRfRoot();
    $folder = feRfFolder($root, 'Reports');
    $component = feRfInside($folder);
    $id = $component->instance()->getId();

    app(Trash::class)->trashFolder($folder->fresh());

    $component->call('onTreeChanged', 'library', $id)
        ->assertSet('currentFolder.id', $folder->id);

    $component->call('onTreeChanged', 'other-scope', 'someone-else')
        ->assertSet('currentFolder.id', $folder->id);

    // Same scope, another component on the page: that one is worth hearing.
    $component->call('onTreeChanged', 'library', 'someone-else')
        ->assertSet('currentFolder.id', $root->id);
});

it('does not poll unless the panel asked for it', function (): void {
    expect(StandaloneSettings::refreshSeconds())->toBe(0);

    feRfComponent()->assertSeeHtml('refreshInterval: 0');
});

it('takes the interval from config or from the panel', function (): void {
    config(['filament-file-explorer.refresh.seconds' => 20]);

    expect(StandaloneSettings::refreshSeconds())->toBe(20);

    feRfComponent()->assertSeeHtml('refreshInterval: 20');

    // Too short an interval would hammer the server from every open explorer.
    config(['filament-file-explorer.refresh.seconds' => 1]);

    expect(StandaloneSettings::refreshSeconds())->toBe(5);

    $plugin = FilamentFileExplorerPlugin::make()->refreshEvery(null);

    expect($plugin->hasRefreshInterval())->toBeTrue()
        ->and($plugin->getRefreshSeconds())->toBeNull();
});
