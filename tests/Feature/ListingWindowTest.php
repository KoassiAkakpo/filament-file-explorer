<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\FolderListing;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

function feRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feFolder(Folder $parent, string $name): Folder
{
    return Folder::query()->create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        'parent_id' => $parent->id,
    ]);
}

/**
 * A media row is enough for the listing: no test here reads the file itself.
 */
function feMediaRow(Folder $folder, string $name, array $attributes = []): Media
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

function feListing(Folder $folder, int $limit, string $search = '', string $sortBy = 'name', string $sortDir = 'asc'): array
{
    return (new FolderListing((int) feRoot()->id, (int) $folder->id, $search, $sortBy, $sortDir))->window($limit);
}

function feCountQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

it('windows folders before files and reports the remainder', function (): void {
    $root = feRoot();

    foreach (range(1, 5) as $i) {
        feFolder($root, 'Folder '.$i);
    }

    foreach (range(1, 5) as $i) {
        feMediaRow($root, 'file-'.$i.'.pdf');
    }

    $first = feListing($root, 4);

    expect($first['folders'])->toHaveCount(4)
        ->and($first['files'])->toHaveCount(0)
        ->and($first['shown'])->toBe(4)
        ->and($first['total'])->toBe(10)
        ->and($first['hasMore'])->toBeTrue();

    $second = feListing($root, 8);

    // The file budget is what is left of the window once every folder is
    // accounted for, so widening it starts showing files.
    expect($second['folders'])->toHaveCount(5)
        ->and($second['files'])->toHaveCount(3)
        ->and($second['hasMore'])->toBeTrue();

    $third = feListing($root, 10);

    expect($third['shown'])->toBe(10)
        ->and($third['hasMore'])->toBeFalse();
});

it('only appends when the window grows, even for items sharing a sort value', function (): void {
    $root = feRoot();

    // Same name for all of them: without a deterministic tiebreak the window
    // could repeat or skip rows between two calls.
    foreach (range(1, 10) as $i) {
        feMediaRow($root, 'same.pdf');
    }

    $firstIds = feListing($root, 5)['files']->pluck('id')->all();
    $allIds = feListing($root, 10)['files']->pluck('id')->all();

    expect($firstIds)->toHaveCount(5)
        ->and($allIds)->toHaveCount(10)
        ->and(array_slice($allIds, 0, 5))->toBe($firstIds)
        ->and(array_unique($allIds))->toHaveCount(10);
});

it('sorts names case-insensitively in SQL', function (): void {
    $root = feRoot();

    foreach (['cherry.pdf', 'apple.pdf', 'Banana.pdf'] as $name) {
        feMediaRow($root, $name);
    }

    // sqlite compares strings byte by byte, so "Banana" would come first
    // without the LOWER() in the ORDER BY.
    expect(feListing($root, 10)['files']->pluck('name')->all())
        ->toBe(['apple.pdf', 'Banana.pdf', 'cherry.pdf']);

    expect(feListing($root, 10, sortDir: 'desc')['files']->pluck('name')->all())
        ->toBe(['cherry.pdf', 'Banana.pdf', 'apple.pdf']);
});

it('sorts files by date and by kind in SQL', function (): void {
    $root = feRoot();

    feMediaRow($root, 'old.pdf', ['created_at' => '2024-01-01 00:00:00', 'mime_type' => 'text/plain']);
    feMediaRow($root, 'new.pdf', ['created_at' => '2026-01-01 00:00:00', 'mime_type' => 'image/png']);

    expect(feListing($root, 10, sortBy: 'date')['files']->pluck('name')->all())
        ->toBe(['old.pdf', 'new.pdf']);

    expect(feListing($root, 10, sortBy: 'date', sortDir: 'desc')['files']->pluck('name')->all())
        ->toBe(['new.pdf', 'old.pdf']);

    expect(feListing($root, 10, sortBy: 'type')['files']->pluck('mime_type')->all())
        ->toBe(['image/png', 'text/plain']);
});

it('searches the whole scope without the root folder itself', function (): void {
    $root = feRoot();
    $child = feFolder($root, 'Reports');
    $deep = feFolder($child, 'Reports 2024');
    feMediaRow($deep, 'reports-summary.pdf');
    feMediaRow($root, 'unrelated.pdf');

    $listing = feListing($root, 100, 'report');

    expect($listing['folders']->pluck('name')->all())->toBe(['Reports', 'Reports 2024'])
        ->and($listing['files']->pluck('name')->all())->toBe(['reports-summary.pdf'])
        ->and($listing['total'])->toBe(3);
});

it('treats wildcards typed in the search box as literals', function (): void {
    $root = feRoot();
    feMediaRow($root, '100%-done.pdf');
    feMediaRow($root, '1000-done.pdf');

    expect(feListing($root, 100, '%')['files']->pluck('name')->all())->toBe(['100%-done.pdf']);
    expect(feListing($root, 100, '10_0')['files'])->toHaveCount(0);
});

it('bounds the search to the window', function (): void {
    $root = feRoot();

    foreach (range(1, 10) as $i) {
        feMediaRow($root, 'report-'.$i.'.pdf');
    }

    $listing = feListing($root, 3, 'report');

    expect($listing['files'])->toHaveCount(3)
        ->and($listing['total'])->toBe(10)
        ->and($listing['hasMore'])->toBeTrue();
});

it('grows the window through loadMore and resets it on navigation', function (): void {
    config(['filament-file-explorer.listing.per_page' => 2]);

    $root = feRoot();
    $child = feFolder($root, 'Docs');

    foreach (range(1, 6) as $i) {
        feMediaRow($root, 'file-'.$i.'.pdf');
    }

    $component = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $root->id,
    ]);

    expect($component->instance()->listing()['shown'])->toBe(2);

    $component->call('loadMore');

    expect($component->instance()->listing()['shown'])->toBe(4);

    // Navigating is a fresh listing, so the window goes back to one page.
    $component->call('navigateToFolder', $child->id);

    expect($component->get('perPage'))->toBe(2);
});

it('sums a folder size with one aggregate instead of walking it', function (): void {
    $root = feRoot();
    $child = feFolder($root, 'Docs');
    $deep = feFolder($child, 'Archive');

    feMediaRow($child, 'a.pdf', ['size' => 2048]);
    feMediaRow($deep, 'b.pdf', ['size' => 4096]);

    $component = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $root->id,
    ]);

    $component->call('showInfo', 'folder', $child->id);

    expect($component->get('infoItem')['size'])->toBe('6 KB');
});

it('memoises tree walks for the rest of the request', function (): void {
    $root = feRoot();
    $deep = feFolder(feFolder($root, 'A'), 'B');
    $tree = app(FolderTree::class);

    expect(feCountQueries(fn () => $tree->isUnderRoot($deep, (int) $root->id)))->toBeGreaterThan(0);
    expect(feCountQueries(fn () => $tree->isUnderRoot($deep, (int) $root->id)))->toBe(0);
    expect(feCountQueries(fn () => $tree->descendantFolderIdsIncludingRoot((int) $root->id)))->toBeGreaterThan(0);
    expect(feCountQueries(fn () => $tree->descendantFolderIdsIncludingRoot((int) $root->id)))->toBe(0);
});

it('stops describing the old tree once it changed in the same request', function (): void {
    $root = feRoot();

    $component = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $root->id,
    ]);

    // The first render memoises the set of folders under the root. Creating one
    // has to invalidate that, or the sidebar and the search scope keep ignoring
    // the new folder for the rest of the request.
    $component->assertOk();

    $component->call('createNewFolder')
        ->set('newFolderName', 'Fresh')
        ->call('saveNewFolder');

    $names = collect($component->instance()->sidebarTree()[0]['children'])->pluck('name');

    expect($names)->toContain('Fresh');
});

it('gives every listing window a deterministic tiebreak', function (): void {
    $root = feRoot();
    feMediaRow($root, 'same.pdf');
    feFolder($root, 'Docs');

    DB::flushQueryLog();
    DB::enableQueryLog();

    feListing($root, 10);

    $ordered = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'order by'));

    DB::disableQueryLog();

    // Without a tiebreak on the primary key, two rows sharing a sort value can
    // swap between windows, which makes "load more" repeat or skip them.
    expect($ordered)->toHaveCount(2)
        ->and($ordered->every(fn (string $query): bool => str_contains($query, '"id" asc')))->toBeTrue();
});

it('does not hydrate a media collection to count a folder', function (): void {
    $root = feRoot();
    $child = feFolder($root, 'Docs');

    foreach (range(1, 25) as $i) {
        feMediaRow($child, 'file-'.$i.'.pdf');
    }

    $listing = feListing($root, 10);
    $folder = $listing['folders']->firstWhere('id', $child->id);

    // The counts come from withCount, which is what directory.blade.php reads
    // before falling back to a query per folder.
    expect((int) $folder->file_explorer_count)->toBe(25)
        ->and((int) $folder->children_count)->toBe(0);
});

it('renders a load more control only while items are left', function (): void {
    config(['filament-file-explorer.listing.per_page' => 2]);

    $root = feRoot();

    foreach (range(1, 5) as $i) {
        feMediaRow($root, 'file-'.$i.'.pdf');
    }

    $component = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => $root->id,
    ]);

    // The whole translation file is serialised into the Alpine data attribute,
    // so the label alone proves nothing: assert on the control itself.
    $component->assertOk()
        ->assertSeeHtml('wire:click="loadMore"')
        ->assertSee('2 of 5');

    $component->call('loadMore')->call('loadMore');

    $component->assertOk()->assertDontSeeHtml('wire:click="loadMore"');
});

it('sorts the files by size in SQL', function (): void {
    $root = feRoot();

    feMediaRow($root, 'big.pdf', ['size' => 9000]);
    feMediaRow($root, 'small.pdf', ['size' => 10]);
    feMediaRow($root, 'medium.pdf', ['size' => 500]);

    expect(feListing($root, 50, '', 'size', 'asc')['files']->pluck('file_name')->all())
        ->toBe(['small.pdf', 'medium.pdf', 'big.pdf'])
        ->and(feListing($root, 50, '', 'size', 'desc')['files']->pluck('file_name')->all())
        ->toBe(['big.pdf', 'medium.pdf', 'small.pdf']);
});

it('breaks size ties on the name rather than the id', function (): void {
    $root = feRoot();

    // Ties are common — empty files, or several copies of one — so the order has
    // to stay readable, not merely deterministic.
    feMediaRow($root, 'charlie.pdf', ['size' => 0]);
    feMediaRow($root, 'alpha.pdf', ['size' => 0]);
    feMediaRow($root, 'bravo.pdf', ['size' => 0]);

    expect(feListing($root, 50, '', 'size', 'asc')['files']->pluck('file_name')->all())
        ->toBe(['alpha.pdf', 'bravo.pdf', 'charlie.pdf']);
});

it('leaves the folders on their name when sorting by size', function (): void {
    $root = feRoot();

    feFolder($root, 'Zebra');
    feFolder($root, 'Alpha');
    feMediaRow($root, 'file.pdf', ['size' => 10]);

    // A folder has no size of its own, and the only honest one would be the
    // recursive weight of its subtree — a query per folder, or a column to keep
    // in step with every upload. So it falls back to the name and still follows
    // the direction, exactly as it already did for a sort by type. Folders fill
    // the window first whatever the sort, so they are never interleaved with the
    // files they would be compared against.
    expect(feListing($root, 50, '', 'size', 'desc')['folders']->pluck('name')->all())
        ->toBe(['Zebra', 'Alpha'])
        ->and(feListing($root, 50, '', 'size', 'asc')['folders']->pluck('name')->all())
        ->toBe(['Alpha', 'Zebra'])
        // Same shape as the sort that already worked this way.
        ->and(feListing($root, 50, '', 'type', 'desc')['folders']->pluck('name')->all())
        ->toBe(['Zebra', 'Alpha']);
});

it('windows a size sort without repeating or skipping', function (): void {
    $root = feRoot();

    foreach (range(1, 12) as $i) {
        feMediaRow($root, 'f'.$i.'.pdf', ['size' => 100]);
    }

    $first = feListing($root, 5, '', 'size', 'asc')['files']->pluck('file_name')->all();
    $wider = feListing($root, 10, '', 'size', 'asc')['files']->pluck('file_name')->all();

    // Every ORDER BY ends with the primary key, so widening only appends.
    expect(array_slice($wider, 0, 5))->toBe($first);
});

it('keeps a size preference across mounts', function (): void {
    $root = feRoot();

    Livewire::test(FileExplorerComponent::class, ['scopeKey' => 'library', 'rootFolderId' => $root->id])
        ->call('setSort', 'size', 'desc');

    Livewire::test(FileExplorerComponent::class, ['scopeKey' => 'library', 'rootFolderId' => $root->id])
        ->assertSet('sortBy', 'size')
        ->assertSet('sortDir', 'desc');
});

it('refuses a sort it does not know', function (): void {
    $root = feRoot();

    Livewire::test(FileExplorerComponent::class, ['scopeKey' => 'library', 'rootFolderId' => $root->id])
        ->call('setSort', 'colour')
        ->assertSet('sortBy', 'name');
});
