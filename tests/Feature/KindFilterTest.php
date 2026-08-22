<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\FileKinds;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feKfRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feKfComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feKfRootId(),
    ]);
}

function feKfFile(string $name, string $mime, ?int $folderId = null): Media
{
    return Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => $folderId ?? feKfRootId(),
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => $mime,
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

function feKfLibrary(): void
{
    feKfFile('photo.png', 'image/png');
    feKfFile('scan.jpg', 'image/jpeg');
    feKfFile('report.pdf', 'application/pdf');
    feKfFile('notes.txt', 'text/plain');
    feKfFile('budget.csv', 'text/csv');
    feKfFile('deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
    feKfFile('backup.zip', 'application/zip');
    feKfFile('song.mp3', 'audio/mpeg');
    feKfFile('clip.mp4', 'video/mp4');
}

it('narrows the files to one kind', function (): void {
    feKfLibrary();

    $listing = feKfComponent()->call('setKind', 'image')->instance()->listing();

    expect($listing['files']->pluck('file_name')->all())->toEqualCanonicalizing(['photo.png', 'scan.jpg']);
});

it('counts what the filter left, not what was there', function (): void {
    feKfLibrary();

    // The filter runs before the window and before the counts, or "5 of 12"
    // would describe rows the window then dropped.
    $listing = feKfComponent()->call('setKind', 'image')->instance()->listing();

    expect($listing['total'])->toBe(2)
        ->and($listing['hasMore'])->toBeFalse();
});

it('follows load more within the filter', function (): void {
    foreach (range(1, 12) as $i) {
        feKfFile('photo'.$i.'.png', 'image/png');
    }
    feKfFile('report.pdf', 'application/pdf');

    config(['filament-file-explorer.listing.per_page' => 5]);

    $component = feKfComponent()->call('setKind', 'image');

    expect($component->instance()->listing()['shown'])->toBe(5)
        ->and($component->instance()->listing()['total'])->toBe(12)
        ->and($component->instance()->listing()['hasMore'])->toBeTrue();

    $component->call('loadMore');

    $files = $component->instance()->listing()['files'];

    expect($files)->toHaveCount(10)
        // Still only images: widening the window must not widen the filter.
        ->and($files->pluck('mime_type')->unique()->all())->toBe(['image/png']);
});

it('matches each kind on the mime type', function (): void {
    feKfLibrary();

    $expected = [
        'image' => ['photo.png', 'scan.jpg'],
        'pdf' => ['report.pdf'],
        'document' => ['notes.txt'],
        'spreadsheet' => ['budget.csv'],
        'presentation' => ['deck.pptx'],
        'archive' => ['backup.zip'],
        'audio' => ['song.mp3'],
        'video' => ['clip.mp4'],
    ];

    foreach ($expected as $kind => $names) {
        $files = feKfComponent()->call('setKind', $kind)->instance()->listing()['files'];

        expect($files->pluck('file_name')->all())->toEqualCanonicalizing($names, $kind);
    }
});

it('leaves the folders alone', function (): void {
    feKfLibrary();
    FolderModel::query()->create(['name' => 'Docs', 'slug' => 'docs', 'parent_id' => feKfRootId()]);

    // A folder has no kind, and hiding folders would filter the user into a room
    // with no doors — the one thing still needed while hunting for images is a
    // way to go and look somewhere else.
    $listing = feKfComponent()->call('setKind', 'image')->instance()->listing();

    expect($listing['folders']->pluck('name')->all())->toBe(['Docs']);
});

it('toggles off when the active kind is chosen again', function (): void {
    feKfLibrary();

    $component = feKfComponent()->call('setKind', 'image');

    expect($component->get('kind'))->toBe('image');

    $component->call('setKind', 'image');

    expect($component->get('kind'))->toBeNull()
        ->and($component->instance()->listing()['total'])->toBe(9);
});

it('narrows nothing for a kind it does not know', function (): void {
    feKfLibrary();

    $component = feKfComponent()->call('setKind', 'holograms');

    expect($component->get('kind'))->toBeNull()
        ->and($component->instance()->listing()['total'])->toBe(9);
});

it('combines with a search', function (): void {
    feKfFile('holiday-photo.png', 'image/png');
    feKfFile('holiday-notes.txt', 'text/plain');
    feKfFile('work-photo.png', 'image/png');

    $listing = feKfComponent()->call('setKind', 'image')->set('search', 'holiday')->instance()->listing();

    expect($listing['files']->pluck('file_name')->all())->toBe(['holiday-photo.png']);
});

it('narrows every pane of the column view', function (): void {
    $docs = (int) FolderModel::query()->create(['name' => 'Docs', 'slug' => 'docs', 'parent_id' => feKfRootId()])->id;

    feKfFile('root.pdf', 'application/pdf');
    feKfFile('root.png', 'image/png');
    feKfFile('inside.pdf', 'application/pdf');
    feKfFile('inside.png', 'image/png', $docs);

    $panes = feKfComponent()
        ->call('setViewMode', 'columns')
        ->call('navigateToFolder', $docs)
        ->call('setKind', 'image')
        ->instance()
        ->columnPanes();

    // A pane showing files the filter excludes would contradict the one beside
    // it: the filter is a lens on the whole view.
    expect($panes[0]['files']->pluck('file_name')->all())->toBe(['root.png'])
        ->and($panes[1]['files']->pluck('file_name')->all())->toBe(['inside.png']);
});

it('is not remembered across mounts', function (): void {
    feKfLibrary();

    feKfComponent()->call('setKind', 'image');

    // The view mode and the sort are how you like to look at a library; a filter
    // is what you are looking for right now. Coming back to a folder that seems
    // to hold two files would be a bug report, not a convenience.
    expect(feKfComponent()->get('kind'))->toBeNull();
});

it('says on screen that a filter is on', function (): void {
    feKfLibrary();

    $html = feKfComponent()->call('setKind', 'image')->assertOk()->html();

    // Without this a narrowed folder simply looks empty, with nothing saying why.
    expect($html)->toContain('Showing Images only')
        ->toContain('Show all');
});

it('offers every kind it knows how to match', function (): void {
    // The menu and the matcher come from the same list, so one cannot offer a
    // kind the other silently ignores.
    $html = feKfComponent()->assertOk()->html();

    foreach (FileKinds::all() as $kind) {
        expect($html)->toContain("setKind('".$kind."')");
    }
});
