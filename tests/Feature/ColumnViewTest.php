<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feCvRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feCvComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feCvRootId(),
    ])->call('setViewMode', 'columns');
}

function feCvFolder(string $name, ?int $parentId = null): int
{
    return (int) FolderModel::query()->create([
        'name' => $name,
        'slug' => Str::slug($name),
        'parent_id' => $parentId ?? feCvRootId(),
    ])->id;
}

function feCvMedia(string $name, int $folderId): Media
{
    return Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => $folderId,
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 4,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

it('is a view mode the explorer accepts and remembers', function (): void {
    expect(StandaloneSettings::VIEW_MODES)->toContain('columns');

    feCvComponent()->assertSet('viewMode', 'columns');

    // Remembered per scope like the others, so it survives a remount.
    expect(Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feCvRootId(),
    ])->get('viewMode'))->toBe('columns');
});

it('builds one pane per level of the path', function (): void {
    $docs = feCvFolder('Docs');
    $reports = feCvFolder('Reports', $docs);

    $panes = feCvComponent()->call('navigateToFolder', $reports)->instance()->columnPanes();

    expect($panes)->toHaveCount(3)
        ->and(array_map(fn (array $pane): string => $pane['folder']->name, $panes))
        ->toBe(['Library', 'Docs', 'Reports'])
        ->and($panes[2]['isLast'])->toBeTrue()
        ->and($panes[0]['isLast'])->toBeFalse();
});

it('marks the entry the next pane is showing', function (): void {
    $docs = feCvFolder('Docs');
    feCvFolder('Other');

    $panes = feCvComponent()->call('navigateToFolder', $docs)->instance()->columnPanes();

    // The trail through the tree, which is not the selection.
    expect($panes[0]['activeFolderId'])->toBe($docs)
        ->and($panes[1]['activeFolderId'])->toBeNull();
});

it('lists each pane from its own folder', function (): void {
    $docs = feCvFolder('Docs');
    feCvMedia('root.pdf', feCvRootId());
    feCvMedia('inside.pdf', $docs);

    $panes = feCvComponent()->call('navigateToFolder', $docs)->instance()->columnPanes();

    expect($panes[0]['files']->pluck('file_name')->all())->toBe(['root.pdf'])
        ->and($panes[1]['files']->pluck('file_name')->all())->toBe(['inside.pdf']);
});

it('leaves the DOM contract to the last pane alone', function (): void {
    $docs = feCvFolder('Docs');
    feCvMedia('root.pdf', feCvRootId());
    feCvMedia('inside.pdf', $docs);

    $html = feCvComponent()->call('navigateToFolder', $docs)->assertOk()->html();

    // Two items live in the current folder's pane — the file and nothing else —
    // and the panes behind it contribute none. That is what keeps the keyboard,
    // the shift-range and the marquee operating on the folder being browsed
    // rather than on everything along the path.
    expect(substr_count($html, 'data-fe-type='))->toBe(1)
        ->and(substr_count($html, 'data-fe-items'))->toBe(1)
        ->and($html)->toContain('fe-column--last');
});

it('falls back to the flat listing while searching', function (): void {
    $docs = feCvFolder('Docs');
    feCvMedia('report.pdf', $docs);

    $component = feCvComponent()->call('navigateToFolder', $docs)->set('search', 'report');

    // A search answers from the whole scope, which is not a path: pretending the
    // two shapes are one would be a lie about where the results are.
    expect($component->instance()->columnPanes())->toBe([]);
});

it('navigates from a pane behind the last one', function (): void {
    $docs = feCvFolder('Docs');
    $reports = feCvFolder('Reports', $docs);

    $component = feCvComponent()->call('navigateToFolder', $reports);

    expect($component->instance()->columnPanes())->toHaveCount(3);

    // Clicking back up the path drops the panes beyond it.
    $component->call('revealInColumn', $docs);

    expect($component->instance()->columnPanes())->toHaveCount(2)
        ->and($component->instance()->currentFolder->id)->toBe($docs);
});

it('selects the file when a pane behind the last one is clicked', function (): void {
    $docs = feCvFolder('Docs');
    feCvFolder('Deeper', $docs);
    $media = feCvMedia('inside.pdf', $docs);

    $component = feCvComponent()->call('navigateToFolder', $docs);

    $component->call('revealInColumn', $docs, $media->id)
        ->assertSet('selectedFiles', [$media->id]);
});

it('rebuilds the panes when the tree changes', function (): void {
    $docs = feCvFolder('Docs');

    $component = feCvComponent()->call('navigateToFolder', $docs);

    expect($component->instance()->columnPanes()[1]['folders'])->toHaveCount(0);

    $component->call('createNewFolder')->set('newFolderName', 'Fresh')->call('saveNewFolder');

    // The panes are listings, so anything that makes one stale makes them stale.
    expect($component->instance()->columnPanes()[1]['folders']->pluck('name')->all())->toBe(['Fresh']);
});

it('builds nothing for the other view modes', function (): void {
    feCvFolder('Docs');

    $component = feCvComponent()->call('setViewMode', 'grid');

    // A listing per level is not something to pay for when nothing renders it.
    expect($component->instance()->columnPanes())->toBe([]);
});

it('descends into a folder and lands on the first entry of the new pane', function (): void {
    $docs = feCvFolder('Docs');
    $inner = feCvFolder('Inner', $docs);
    feCvMedia('inside.pdf', $docs);

    $component = feCvComponent()->call('columnInto', $docs);

    // Folders come first in the listing, so that is where the Finder leaves you
    // — and a further right arrow can keep descending without the mouse.
    expect($component->instance()->currentFolder->id)->toBe($docs);
    $component->assertSet('selectedFolders', [$inner]);
});

it('lands on the first file when the folder holds no folders', function (): void {
    $docs = feCvFolder('Docs');
    $media = feCvMedia('only.pdf', $docs);

    feCvComponent()->call('columnInto', $docs)->assertSet('selectedFiles', [$media->id]);
});

it('refuses to descend into a folder outside the root', function (): void {
    $outside = (int) FolderModel::query()->create(['name' => 'Out', 'slug' => 'out', 'parent_id' => null])->id;

    feCvComponent()->call('columnInto', $outside)->assertForbidden();
});

it('walks back out with the folder just left selected', function (): void {
    $docs = feCvFolder('Docs');
    $reports = feCvFolder('Reports', $docs);

    $component = feCvComponent()->call('navigateToFolder', $reports)->call('columnBack');

    // Selected, so a further left arrow keeps walking up and the pane you came
    // from stays marked.
    expect($component->instance()->currentFolder->id)->toBe($docs);
    $component->assertSet('selectedFolders', [$reports]);
});

it('stays put at the root', function (): void {
    $component = feCvComponent()->call('columnBack');

    expect($component->instance()->currentFolder->id)->toBe(feCvRootId());
});

it('makes every pane a drop target and its empty space a way to deselect', function (): void {
    $docs = feCvFolder('Docs');
    feCvFolder('Deeper', $docs);

    $html = feCvComponent()->call('navigateToFolder', $docs)->assertOk()->html();

    // Counted on the pane rows themselves: the sidebar tree exposes drop
    // targets too, so counting the whole page would prove nothing.
    preg_match_all('/<(?:div|button)\b[^>]*fe-column__row[^>]*>/', $html, $matches);
    $rows = $matches[0];
    $droppable = array_values(array_filter($rows, fn (string $tag): bool => str_contains($tag, 'data-fe-drop-folder')));

    // Dropping onto a folder in a pane behind the last one has to work, or the
    // view shows a destination that cannot be used.
    expect($rows)->toHaveCount(2)
        ->and($droppable)->toHaveCount(2)
        // The panes fill the container, so the container never receives the
        // click that used to clear the selection.
        ->and($html)->toContain('sel.clear()')
        ->and($html)->toContain('data-fe-pane');
});

it('gives the selection marker something to look like', function (): void {
    // is-selected is only a marker — each view supplies its own visual. Without
    // a rule the toolbar counted a selection the panes never showed.
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/file-explorer.css');

    expect($css)->toContain('.fe-column__row.is-selected')
        ->toContain('.fe-column__row.fe-drop-target');
});

it('tells the JS which view is rendered without putting it in x-data', function (): void {
    // In x-data it would rewrite that attribute on every switch; in the DOM it
    // is a fact about what is rendered.
    expect(feCvComponent()->assertOk()->html())->toContain('data-fe-view="columns"');
});
