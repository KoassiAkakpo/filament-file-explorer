<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Widgets\StorageWidget;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');

    // Off by default, so every test here says so explicitly.
    config()->set('filament-file-explorer.standalone.storage_widget', true);
});

function feSwRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feSwMedia(int $bytes, string $collection = ''): Media
{
    return Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => feSwRootId(),
        'collection_name' => $collection !== '' ? $collection : UploadRules::collection(),
        'name' => 'file-'.Str::random(5).'.pdf',
        'file_name' => 'file-'.Str::random(5).'.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => $bytes,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

function feSwStorage(): ?array
{
    app()->forgetScopedInstances();

    return Livewire::test(StorageWidget::class)->instance()->getStorage();
}

it('is off unless the panel asks for it', function (): void {
    config()->set('filament-file-explorer.standalone.storage_widget', false);

    // A widget on someone's dashboard the day they upgrade would be a surprise.
    expect(StorageWidget::canView())->toBeFalse();

    config()->set('filament-file-explorer.standalone.storage_widget', true);
    feSwRootId();

    expect(StorageWidget::canView())->toBeTrue();
});

it('reports what the scope holds with no quota set', function (): void {
    feSwRootId();
    feSwMedia(2048);
    feSwMedia(1024);

    $storage = feSwStorage();

    // No cap: the total is still the useful half, and a bar with nothing to
    // fill would only invent a limit.
    expect($storage['limit'])->toBeNull()
        ->and($storage['files'])->toBe(2)
        ->and($storage['used_label'])->toContain('3');
});

it('reports the quota when there is one', function (): void {
    config()->set('filament-file-explorer.quota.bytes', 1000);

    feSwRootId();
    feSwMedia(250);

    $storage = feSwStorage();

    expect($storage['limit']['percent'])->toBe(25)
        ->and($storage['limit']['warning'])->toBeFalse()
        ->and($storage['limit']['full'])->toBeFalse();
});

it('counts trashed files, as the quota does', function (): void {
    config()->set('filament-file-explorer.quota.bytes', 1000);

    feSwRootId();
    feSwMedia(400);
    feSwMedia(400, Trash::collection());

    // They sit on the disk until they are purged. A widget that stopped
    // counting them would disagree with the upload that then gets refused.
    expect(feSwStorage()['limit']['percent'])->toBe(80)
        ->and(feSwStorage()['files'])->toBe(2);
});

it('warns before it is full, and says when it is', function (): void {
    config()->set('filament-file-explorer.quota.bytes', 100);

    feSwRootId();
    feSwMedia(90);

    expect(feSwStorage()['limit']['warning'])->toBeTrue()
        ->and(feSwStorage()['limit']['full'])->toBeFalse();

    feSwMedia(20);

    expect(feSwStorage()['limit']['full'])->toBeTrue()
        // Capped, so a scope over its allowance does not draw past the bar.
        ->and(feSwStorage()['limit']['percent'])->toBe(100);
});

it('never creates the root folder it would measure', function (): void {
    // The dashboard renders this for every authenticated user, exactly like the
    // navigation — which is why canAccess() may not create either.
    expect(FolderModel::query()->count())->toBe(0);

    StorageWidget::canView();

    expect(FolderModel::query()->count())->toBe(0);
});

it('says nothing at all before the scope has a root', function (): void {
    expect(StorageWidget::canView())->toBeFalse()
        ->and(feSwStorage())->toBeNull();
});

it('hides itself when the scope is refused', function (): void {
    feSwRootId();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function canAccess(string $scopeKey, int $rootFolderId): bool
        {
            return false;
        }
    });
    app()->forgetScopedInstances();

    expect(StorageWidget::canView())->toBeFalse();
});

it('hides itself when browsing is denied', function (): void {
    feSwRootId();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return array_merge(array_fill_keys(Abilities::ORIGINAL, true), ['browse' => false]);
        }
    });
    app()->forgetScopedInstances();

    // Both guards, as everywhere else: the scope, then the ability. Reading how
    // much a library holds is part of browsing it.
    expect(StorageWidget::canView())->toBeFalse();
});

it('renders on the dashboard', function (): void {
    config()->set('filament-file-explorer.quota.bytes', 1000);

    feSwRootId();
    feSwMedia(250);

    $storage = feSwStorage();

    Livewire::test(StorageWidget::class)
        ->assertOk()
        ->assertSee(__('filament-file-explorer::file-explorer.widget.of_limit', [
            'percent' => 25,
            'limit' => $storage['limit']['limit_label'],
        ]))
        ->assertSee(__('filament-file-explorer::file-explorer.widget.title'));
});

it('links to the explorer page the panel registered', function (): void {
    feSwRootId();

    expect(feSwStorage()['url'])->toBeString()
        ->and(feSwStorage()['url'])->toContain('file-explorer');
});
