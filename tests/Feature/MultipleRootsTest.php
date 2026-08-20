<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Contracts\ProvidesMultipleRoots;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer as FileExplorerPage;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Resolvers\GlobalRootResolver;
use Koassi\FilamentFileExplorer\Support\ActiveRoot;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Two roots for one scope: "Mine" and "Shared", the way a Finder sidebar shows
 * locations.
 */
class FeMrResolver implements ProvidesMultipleRoots
{
    public function scopeKey(): string
    {
        return 'library';
    }

    public function rootFolderId(): int
    {
        return $this->roots()[0]['id'];
    }

    public function roots(): array
    {
        $manager = app(FileExplorerManager::class);

        return [
            ['id' => (int) $manager->ensureRoot('mine', 'Mine')->id, 'name' => 'Mine'],
            ['id' => (int) $manager->ensureRoot('shared', 'Shared')->id, 'name' => 'Shared'],
        ];
    }
}

beforeEach(function (): void {
    Storage::fake('public');
    config(['filament-file-explorer.standalone.resolver' => FeMrResolver::class]);
});

function feMrRoots(): array
{
    return array_map(fn (array $root): int => $root['id'], app(FileExplorerRootResolver::class)->roots());
}

function feMrComponent(?int $rootFolderId = null): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $rootFolderId ?? feMrRoots()[0],
    ]);
}

function feMrMedia(int $folderId, string $name): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $folderId,
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

it('offers every root of the scope, and only for that scope', function (): void {
    [$mine, $shared] = feMrRoots();

    expect(ActiveRoot::options('library'))->toBe([
        ['id' => $mine, 'name' => 'Mine'],
        ['id' => $shared, 'name' => 'Shared'],
    ])
        // A record-scoped explorer has its own scope key and no switcher.
        ->and(ActiveRoot::options('project.1'))->toBe([]);
});

it('offers nothing to switch to with an ordinary resolver', function (): void {
    config(['filament-file-explorer.standalone.resolver' => GlobalRootResolver::class]);

    expect(ActiveRoot::options('library'))->toBe([]);

    feMrComponent(app(FileExplorerRootResolver::class)->rootFolderId())
        ->assertDontSeeHtml('wire:click="switchRoot');
});

it('shows the roots in the sidebar', function (): void {
    [$mine, $shared] = feMrRoots();

    feMrComponent($mine)
        ->assertSee('Locations')
        ->assertSeeHtml('wire:click="switchRoot('.$shared.')"')
        ->assertSeeHtml('aria-selected="true"');
});

it('moves the whole explorer to another root', function (): void {
    [$mine, $shared] = feMrRoots();

    $inShared = Folder::query()->create(['name' => 'Theirs', 'slug' => 'theirs', 'parent_id' => $shared]);
    Folder::query()->create(['name' => 'Ours', 'slug' => 'ours', 'parent_id' => $mine]);

    $component = feMrComponent($mine);

    $component->assertSee('Ours')->assertDontSee('Theirs');

    $component->call('switchRoot', $shared)
        ->assertSet('rootFolderId', $shared)
        ->assertSet('currentFolder.id', $shared)
        ->assertSee('Theirs')
        ->assertDontSee('Ours');

    expect($component->instance()->sidebarTree()[0]['id'])->toBe($shared)
        ->and($component->get('breadcrumb'))->toHaveCount(1);

    // The switcher marks the root it moved to.
    $component->assertSeeHtml('aria-selected="true"');

    // And the folder of the new root is reachable, which containment against
    // the old one would have refused.
    $component->call('navigateToFolder', $inShared->id)->assertSet('currentFolder.id', $inShared->id);
});

it('refuses a root the resolver does not offer', function (): void {
    [$mine] = feMrRoots();

    $foreign = app(FileExplorerManager::class)->createRoot('Someone else', 'someone-else');

    $component = feMrComponent($mine);

    $component->call('switchRoot', $foreign->id)->assertForbidden();

    // Not even a folder that exists inside the scope's own roots: only a root.
    $child = Folder::query()->create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $mine]);

    feMrComponent($mine)->call('switchRoot', $child->id)->assertForbidden();
});

it('refuses a root the authorizer denies', function (): void {
    [$mine, $shared] = feMrRoots();

    app()->instance(FileExplorerAuthorizer::class, new class($shared) extends AllowAllAuthorizer
    {
        public function __construct(private int $denied) {}

        public function canAccess(string $scopeKey, int $rootFolderId): bool
        {
            return $rootFolderId !== $this->denied;
        }
    });

    feMrComponent($mine)->call('switchRoot', $shared)->assertForbidden();
});

it('remembers the root across page loads', function (): void {
    [$mine, $shared] = feMrRoots();

    feMrComponent($mine)->call('switchRoot', $shared);

    $page = new FileExplorerPage;
    $page->mount();

    expect($page->rootFolderId)->toBe($shared);
});

it('falls back to the first root when the stored one is withdrawn', function (): void {
    [$mine, $shared] = feMrRoots();

    ActiveRoot::choose('library', $shared);

    // The resolver stops offering it — a revoked share, say.
    app()->instance(FileExplorerRootResolver::class, new class($mine) implements ProvidesMultipleRoots
    {
        public function __construct(private int $mine) {}

        public function scopeKey(): string
        {
            return 'library';
        }

        public function rootFolderId(): int
        {
            return $this->mine;
        }

        public function roots(): array
        {
            return [['id' => $this->mine, 'name' => 'Mine']];
        }
    });

    expect(ActiveRoot::idFor(app(FileExplorerRootResolver::class)))->toBe($mine);
});

it('keeps the current folder and the clipboard of each root apart', function (): void {
    [$mine, $shared] = feMrRoots();

    $inMine = Folder::query()->create(['name' => 'Ours', 'slug' => 'ours', 'parent_id' => $mine]);
    $file = feMrMedia($inMine->id, 'ours.pdf');

    $component = feMrComponent($mine);
    $component->call('navigateToFolder', $inMine->id)
        ->call('copySelection', null, $file->id);

    expect($component->get('clipboardReady'))->toBeTrue();

    // Switching root drops both: pasting a file of another root would fail
    // containment, and the remembered folder is not a folder of this tree.
    $component->call('switchRoot', $shared)
        ->assertSet('currentFolder.id', $shared)
        ->assertSet('clipboardReady', false);

    // Coming back finds the folder we were left in, and the clipboard that
    // belongs to it.
    $component->call('switchRoot', $mine)
        ->assertSet('currentFolder.id', $inMine->id)
        ->assertSet('clipboardReady', true);
});

it('serves media of any root of the scope', function (): void {
    [$mine, $shared] = feMrRoots();

    $inShared = feMrMedia($shared, 'theirs.pdf');
    Storage::disk('public')->put($inShared->id.'/theirs.pdf', 'x');

    // Browsing "Mine" must not make a link into "Shared" fail: the two roots
    // are one scope, with one ability set.
    feMrComponent($mine);

    $this->actingAs(new User(['id' => 1]))
        ->get(route('filament-file-explorer.media.show', ['scopeKey' => 'library', 'media' => $inShared->id]))
        ->assertOk();
});

it('still refuses media outside every root of the scope', function (): void {
    $foreign = app(FileExplorerManager::class)->createRoot('Someone else', 'someone-else');
    $media = feMrMedia((int) $foreign->id, 'secret.pdf');

    feMrComponent();

    $this->actingAs(new User(['id' => 1]))
        ->get(route('filament-file-explorer.media.show', ['scopeKey' => 'library', 'media' => $media->id]))
        ->assertForbidden();
});

it('reads a registry written before a scope could hold several roots', function (): void {
    [$mine] = feMrRoots();

    // The old shape: one bare id per scope key.
    session(['filament-file-explorer.roots.guest' => ['library' => $mine]]);

    expect(ScopeRoots::resolveAll('library'))->toBe([$mine])
        ->and(ScopeRoots::resolve('library'))->toBe($mine);
});
