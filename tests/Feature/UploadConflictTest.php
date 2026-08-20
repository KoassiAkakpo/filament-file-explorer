<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\FileNames;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feUpRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feUpComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feUpRoot()->id,
    ]);
}

function feUpload(Testable $component, string $name = 'report.pdf'): Testable
{
    return $component->set('files', [UploadedFile::fake()->create($name, 4, 'application/pdf')]);
}

function feUpFiles(Folder $folder): array
{
    return Media::query()
        ->where('collection_name', UploadRules::collection())
        ->where('model_type', (new Folder)->getMorphClass())
        ->where('model_id', $folder->id)
        ->orderBy('id')
        ->get(['name', 'file_name'])
        ->map(fn (Media $media): array => [$media->file_name, $media->name])
        ->all();
}

it('keeps both files when a name is already taken', function (): void {
    $root = feUpRoot();

    feUpload(feUpComponent());
    feUpload(feUpComponent());

    // The stored name follows the slug convention, the label the one a desktop
    // uses — two files with the same label were indistinguishable before.
    expect(feUpFiles($root))->toBe([
        ['report.pdf', 'report.pdf'],
        ['report-2.pdf', 'report (2).pdf'],
    ]);
});

it('numbers further collisions upwards', function (): void {
    $root = feUpRoot();

    feUpload(feUpComponent());
    feUpload(feUpComponent());
    feUpload(feUpComponent());

    expect(collect(feUpFiles($root))->pluck(0)->all())
        ->toBe(['report.pdf', 'report-2.pdf', 'report-3.pdf']);
});

it('replaces the existing file when configured to', function (): void {
    config(['filament-file-explorer.upload.on_conflict' => 'replace']);

    $root = feUpRoot();

    feUpload(feUpComponent());
    $first = Media::query()->firstOrFail();

    feUpload(feUpComponent());

    expect(feUpFiles($root))->toBe([['report.pdf', 'report.pdf']])
        ->and(Media::query()->find($first->id))->toBeNull();
});

it('refuses the new file when configured to skip', function (): void {
    config(['filament-file-explorer.upload.on_conflict' => 'skip']);

    $root = feUpRoot();

    feUpload(feUpComponent());
    $first = Media::query()->firstOrFail();

    feUpload(feUpComponent());

    expect(feUpFiles($root))->toBe([['report.pdf', 'report.pdf']])
        ->and(Media::query()->find($first->id))->not->toBeNull();
});

it('falls back to keeping both when the policy is unknown', function (): void {
    config(['filament-file-explorer.upload.on_conflict' => 'whatever']);

    expect(UploadRules::onConflict())->toBe('rename');
});

it('never overwrites when copying, whatever the upload policy is', function (): void {
    config(['filament-file-explorer.upload.on_conflict' => 'replace']);

    $root = feUpRoot();
    feUpload(feUpComponent());
    $media = Media::query()->firstOrFail();

    // Pasting into the folder the file already lives in.
    feUpComponent()
        ->call('copySelection', null, $media->id)
        ->call('pasteClipboard');

    expect(collect(feUpFiles($root))->pluck(0)->all())->toBe(['report.pdf', 'report-2.pdf']);
});

it('suffixes names and labels independently', function (): void {
    expect(FileNames::applyIndex('report.pdf', 1))->toBe('report.pdf')
        ->and(FileNames::applyIndex('report.pdf', 3))->toBe('report-3.pdf')
        ->and(FileNames::applyIndex('archive.tar.gz', 2))->toBe('archive.tar-2.gz')
        ->and(FileNames::applyIndex('noextension', 2))->toBe('noextension-2')
        ->and(FileNames::applyIndexToLabel('Report.pdf', 2))->toBe('Report (2).pdf')
        ->and(FileNames::applyIndexToLabel('Report.pdf', 1))->toBe('Report.pdf');
});

it('matches an existing name regardless of case', function (): void {
    $root = feUpRoot();

    Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $root->id,
        'collection_name' => UploadRules::collection(),
        'name' => 'Report.PDF',
        'file_name' => 'Report.PDF',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    expect(FileNames::availableIndex($root, 'report.pdf'))->toBe(2)
        ->and(FileNames::existing($root, 'report.pdf'))->not->toBeNull();
});
