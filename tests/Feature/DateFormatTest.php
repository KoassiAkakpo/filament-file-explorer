<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Dates;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feDateRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feDateMedia(Folder $folder, string $name, array $attributes = []): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => $folder->id,
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 2048,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        ...$attributes,
    ]);
}

function feDateComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feDateRoot()->id,
    ]);
}

it('writes the date the way it always did until the setting says otherwise', function (): void {
    expect(Dates::format(Carbon::parse('2026-09-01 14:30')))->toBe('2026/09/01 14:30');
});

it('follows the configured pattern', function (): void {
    config()->set('filament-file-explorer.dates.format', 'd/m/Y H:i');

    expect(Dates::format(Carbon::parse('2026-09-01 14:30')))->toBe('01/09/2026 14:30');
});

it('writes the month in the application locale', function (): void {
    config()->set('filament-file-explorer.dates.format', 'j F Y');

    app()->setLocale('fr');
    expect(Dates::format(Carbon::parse('2026-09-01')))->toBe('1 septembre 2026');

    app()->setLocale('en');
    expect(Dates::format(Carbon::parse('2026-09-01')))->toBe('1 September 2026');
});

it('pins the language when the setting names one', function (): void {
    config()->set('filament-file-explorer.dates.format', 'j F Y');
    config()->set('filament-file-explorer.dates.locale', 'fr');

    app()->setLocale('en');

    expect(Dates::format(Carbon::parse('2026-09-01')))->toBe('1 septembre 2026');
});

it('picks the pattern of the locale in force', function (): void {
    config()->set('filament-file-explorer.dates.format', [
        'fr' => 'd/m/Y',
        'en' => 'm-d-Y',
        'default' => 'Y-m-d',
    ]);

    app()->setLocale('fr');
    expect(Dates::format(Carbon::parse('2026-09-01')))->toBe('01/09/2026');

    app()->setLocale('en');
    expect(Dates::format(Carbon::parse('2026-09-01')))->toBe('09-01-2026');

    app()->setLocale('de');
    expect(Dates::format(Carbon::parse('2026-09-01')))->toBe('2026-09-01');
});

it('answers a regional locale with its language', function (): void {
    config()->set('filament-file-explorer.dates.format', ['fr' => 'd/m/Y', 'default' => 'Y-m-d']);

    app()->setLocale('fr_CA');

    expect(Dates::format(Carbon::parse('2026-09-01')))->toBe('01/09/2026');
});

it('falls back to the default pattern for an unusable setting', function (): void {
    config()->set('filament-file-explorer.dates.format', 42);

    expect(Dates::format(Carbon::parse('2026-09-01 14:30')))->toBe('2026/09/01 14:30');

    config()->set('filament-file-explorer.dates.format', ['fr' => 'd/m/Y']);
    app()->setLocale('en');

    expect(Dates::format(Carbon::parse('2026-09-01 14:30')))->toBe('2026/09/01 14:30');
});

it('answers a missing date with what the caller asked for', function (): void {
    expect(Dates::format(null))->toBeNull()
        ->and(Dates::formatOrPlaceholder(null))->toBe('—')
        ->and(Dates::formatOrPlaceholder('not a date'))->toBe('—');
});

it('formats the inspector with the configured pattern', function (): void {
    config()->set('filament-file-explorer.dates.format', 'd/m/Y H:i');

    $media = feDateMedia(feDateRoot(), 'note.pdf');
    $media->created_at = Carbon::parse('2026-09-01 14:30');
    $media->updated_at = Carbon::parse('2026-09-02 09:05');
    $media->saveQuietly();

    $component = feDateComponent();
    $component->call('showInfo', 'file', $media->id);

    expect($component->get('infoItem')['created'])->toBe('01/09/2026 14:30')
        ->and($component->get('infoItem')['updated'])->toBe('02/09/2026 09:05');
});

it('formats the details view with the configured pattern', function (): void {
    config()->set('filament-file-explorer.dates.format', 'd/m/Y H:i');

    $media = feDateMedia(feDateRoot(), 'note.pdf');
    $media->created_at = Carbon::parse('2026-09-01 14:30');
    $media->saveQuietly();

    feDateComponent()
        ->call('setViewMode', 'details')
        ->assertSee('01/09/2026 14:30');
});

it('formats the lightbox with the configured pattern', function (): void {
    config()->set('filament-file-explorer.dates.format', 'd/m/Y H:i');

    $media = feDateMedia(feDateRoot(), 'photo.png', ['mime_type' => 'image/png']);
    $media->created_at = Carbon::parse('2026-09-01 14:30');
    $media->saveQuietly();

    $component = feDateComponent();
    $component->call('preview', $media->id);

    expect($component->get('previewItem')['created'])->toBe('01/09/2026 14:30');

    $component->assertSee('01/09/2026 14:30');
});

it('formats the trash with the configured pattern', function (): void {
    config()->set('filament-file-explorer.dates.format', 'd/m/Y H:i');

    $folder = Folder::query()->create([
        'name' => 'Archive',
        'slug' => 'archive',
        'parent_id' => feDateRoot()->id,
    ]);

    Carbon::setTestNow('2026-09-01 14:30');
    $folder->delete();
    Carbon::setTestNow();

    $rows = feDateComponent()->call('toggleTrash')->instance()->trashItems();

    expect($rows[0]['deleted_at'])->toBe('01/09/2026 14:30');
});

it('lets the panel override config', function (): void {
    config()->set('filament-file-explorer.dates.format', 'd/m/Y H:i');

    Filament::setCurrentPanel('admin');

    Filament::getCurrentPanel()
        ->getPlugin('filament-file-explorer')
        ->dateFormat('Y.m.d')
        ->dateLocale('fr');

    expect(Dates::format(Carbon::parse('2026-09-01 14:30')))->toBe('2026.09.01');
});
