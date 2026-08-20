<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feQuRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feQuComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feQuRoot()->id,
    ]);
}

function feQuMedia(Folder $folder, int $size, string $name = 'report.pdf', string $collection = ''): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $folder->id,
        'collection_name' => $collection !== '' ? $collection : UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => $size,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

/**
 * An upload that really weighs $kb kilobytes.
 *
 * UploadedFile::fake()->create() fakes getSize() but writes an empty file, so
 * Media Library would store a size of 0 and no quota could be tested at all.
 */
function feQuFile(int $kb, string $name = 'upload.pdf'): UploadedFile
{
    $header = '%PDF-1.4'."\n";

    return UploadedFile::fake()->createWithContent($name, $header.str_repeat('0', $kb * 1024 - strlen($header)));
}

function feQuUpload(int $kb, string $name = 'upload.pdf'): Testable
{
    return feQuComponent()->set('files', [feQuFile($kb, $name)]);
}

it('has no limit until one is set', function (): void {
    feQuMedia(feQuRoot(), 5_000_000);

    expect(app(Quota::class)->limitBytes())->toBeNull()
        ->and(app(Quota::class)->state(feQuRoot()->id))->toBeNull()
        ->and(app(Quota::class)->fits(feQuRoot()->id, PHP_INT_MAX))->toBeTrue();
});

it('counts every file under the root, however deep', function (): void {
    config(['filament-file-explorer.quota.bytes' => 1_000_000]);

    $root = feQuRoot();
    $nested = Folder::query()->create(['name' => 'Deep', 'slug' => 'deep', 'parent_id' => $root->id]);

    feQuMedia($root, 100, 'a.pdf');
    feQuMedia($nested, 250, 'b.pdf');

    expect(app(Quota::class)->usedBytes($root->id))->toBe(350);
});

it('counts trashed files, which still sit on the disk', function (): void {
    config(['filament-file-explorer.quota.bytes' => 1_000_000]);

    $root = feQuRoot();
    feQuMedia($root, 400, 'live.pdf');
    feQuMedia($root, 600, 'gone.pdf', Trash::collection());

    // Otherwise deleting and re-uploading would be a way over the cap.
    expect(app(Quota::class)->usedBytes($root->id))->toBe(1000);
});

it('ignores files of another scope', function (): void {
    config(['filament-file-explorer.quota.bytes' => 1_000_000]);

    $other = Folder::query()->create(['name' => 'Other', 'slug' => 'other', 'parent_id' => null]);
    feQuMedia($other, 900);

    expect(app(Quota::class)->usedBytes(feQuRoot()->id))->toBe(0);
});

it('refuses an upload that would go over the cap', function (): void {
    config(['filament-file-explorer.quota.bytes' => 30 * 1024]);

    feQuMedia(feQuRoot(), 25 * 1024, 'already-there.pdf');

    feQuUpload(20);

    // Nothing was written: the check runs before the file is stored.
    expect(Media::query()->where('file_name', 'upload.pdf')->exists())->toBeFalse()
        ->and(Media::query()->count())->toBe(1);
});

it('accepts what still fits', function (): void {
    config(['filament-file-explorer.quota.bytes' => 100 * 1024]);

    feQuMedia(feQuRoot(), 25 * 1024, 'already-there.pdf');

    feQuUpload(20);

    expect(Media::query()->where('file_name', 'upload.pdf')->exists())->toBeTrue();
});

it('stops at the cap in the middle of a multi-file upload', function (): void {
    config(['filament-file-explorer.quota.bytes' => 25 * 1024]);

    feQuComponent()->set('files', [
        feQuFile(10, 'one.pdf'),
        feQuFile(10, 'two.pdf'),
        feQuFile(10, 'three.pdf'),
    ]);

    // The third does not fit: the reservation has to accumulate within one
    // request, not re-read a total the previous files have already changed.
    expect(Media::query()->pluck('file_name')->sort()->values()->all())->toBe(['one.pdf', 'two.pdf']);
});

it('counts only the difference when a file is replaced', function (): void {
    config([
        'filament-file-explorer.quota.bytes' => 30 * 1024,
        'filament-file-explorer.upload.on_conflict' => 'replace',
    ]);

    $root = feQuRoot();
    feQuMedia($root, 25 * 1024, 'upload.pdf');

    feQuUpload(20);

    // 20 KB does not fit next to 25 KB, but it does in its place.
    expect(Media::query()->count())->toBe(1)
        ->and((int) Media::query()->firstOrFail()->size)->toBeLessThan(25 * 1024);
});

it('refuses a copy that would go over the cap', function (): void {
    config(['filament-file-explorer.quota.bytes' => 30 * 1024]);

    feQuUpload(20);

    $media = Media::query()->firstOrFail();
    $target = Folder::query()->create(['name' => 'Copy', 'slug' => 'copy', 'parent_id' => feQuRoot()->id]);

    feQuComponent()->call('copyItemsToFolder', $target->id, [], [$media->id]);

    expect(Media::query()->count())->toBe(1);
});

it('reports the usage for the sidebar', function (): void {
    config(['filament-file-explorer.quota.bytes' => 1000]);

    feQuMedia(feQuRoot(), 900);

    $state = app(Quota::class)->state(feQuRoot()->id);

    expect($state['percent'])->toBe(90)
        ->and($state['warning'])->toBeTrue()
        ->and($state['full'])->toBeFalse()
        ->and($state['limit_label'])->toBe('1.0 KB');

    feQuComponent()->assertSee('90%')->assertSeeHtml('aria-valuenow="90"');
});

it('shows nothing in the sidebar without a limit', function (): void {
    feQuComponent()->assertDontSeeHtml('role="progressbar"');
});

it('takes the limit from the panel before config', function (): void {
    config(['filament-file-explorer.quota.bytes' => 1_000_000]);

    $plugin = FilamentFileExplorerPlugin::make()->quota(500);

    expect($plugin->hasQuota())->toBeTrue()
        ->and($plugin->getQuotaBytes())->toBe(500);

    // ->quota(null) has to be able to lift a limit config had set, which a
    // null value alone could not express.
    expect(FilamentFileExplorerPlugin::make()->quota(null)->hasQuota())->toBeTrue();
});

it('drops its memo when the listing resets', function (): void {
    config(['filament-file-explorer.quota.bytes' => 1_000_000]);

    $root = feQuRoot();
    $quota = app(Quota::class);

    expect($quota->usedBytes($root->id))->toBe(0);

    feQuMedia($root, 700, Str::random(6).'.pdf');

    // Memoised, so still 0 until something says the tree may have changed.
    expect($quota->usedBytes($root->id))->toBe(0);

    $quota->flush();

    expect($quota->usedBytes($root->id))->toBe(700);
});
