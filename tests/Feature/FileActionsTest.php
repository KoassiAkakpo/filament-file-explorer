<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Every action the explorer offers on a file, exercised on its happy path.
 *
 * The containment refusals are covered elsewhere; what these guard is the code
 * *after* the check — the branch that reads the media's folder, renames it,
 * duplicates the file on disk. A missed variable there only shows up at
 * runtime, which is exactly how "Get Info" broke.
 */
beforeEach(function (): void {
    Storage::fake('public');
});

function feActRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feActFolder(Folder $parent, string $name): Folder
{
    return Folder::query()->create([
        'name' => $name,
        'slug' => Str::slug($name),
        'parent_id' => $parent->id,
    ]);
}

function feActFile(Folder $folder, string $name = 'report.pdf'): Media
{
    $path = sys_get_temp_dir().'/'.uniqid('feact_').'-'.$name;
    file_put_contents($path, 'contents');

    return $folder->addMedia($path)
        ->usingName($name)
        ->usingFileName($name)
        ->toMediaCollection(UploadRules::collection());
}

function feActComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feActRoot()->id,
    ]);
}

it('reports the details of a file, path included', function (): void {
    $root = feActRoot();
    $folder = feActFolder($root, 'Invoices');
    $media = feActFile($folder, 'march.pdf');

    $component = feActComponent();
    $component->call('showInfo', 'file', $media->id)->assertOk();

    $info = $component->get('infoItem');

    expect($info['type'])->toBe('file')
        ->and($info['name'])->toBe('march.pdf')
        ->and($info['path'])->toBe($root->name.' / Invoices / march.pdf')
        ->and($info['size'])->toBe('8 B');
});

it('reports the details of the folder behind an image, with a preview url', function (): void {
    $media = feActFile(feActRoot(), 'photo.png');
    $media->forceFill(['mime_type' => 'image/png'])->save();

    $component = feActComponent();
    $component->call('showInfo', 'file', $media->id)->assertOk();

    expect($component->get('infoItem')['preview'])->toContain('/files/'.$media->id);
});

it('renames a file', function (): void {
    $media = feActFile(feActRoot(), 'draft.pdf');

    feActComponent()
        ->call('startRename', 'file', $media->id)
        ->assertSet('renamingType', 'file')
        ->set('renameValue', 'final')
        ->call('saveRename')
        ->assertOk();

    expect($media->refresh()->name)->toBe('final');
});

it('answers the delete state of a file', function (): void {
    $media = feActFile(feActRoot(), 'draft.pdf');

    $state = feActComponent()->instance()->getDeleteState('file', $media->id);

    expect($state['allowed'])->toBeTrue()
        ->and($state)->toHaveKey('hint');
});

it('copies a file into another folder', function (): void {
    $root = feActRoot();
    $target = feActFolder($root, 'Archive');
    $media = feActFile($root, 'report.pdf');

    feActComponent()->call('copyItemsToFolder', $target->id, [], [$media->id])->assertOk();

    expect($target->getMedia(UploadRules::collection()))->toHaveCount(1)
        ->and(Media::query()->find($media->id))->not->toBeNull();
});

it('moves a file into another folder', function (): void {
    $root = feActRoot();
    $target = feActFolder($root, 'Archive');
    $media = feActFile($root, 'report.pdf');

    feActComponent()->call('moveItemsToFolder', $target->id, [], [$media->id])->assertOk();

    expect((int) $media->refresh()->model_id)->toBe((int) $target->id);
});

it('pastes a copied file into the current folder', function (): void {
    $root = feActRoot();
    $source = feActFolder($root, 'Source');
    $media = feActFile($source, 'report.pdf');

    $component = feActComponent();
    $component->call('copySelection', null, $media->id)
        ->call('pasteClipboard')
        ->assertOk();

    // Pasted into the folder being viewed, which is the root here.
    expect($root->getMedia(UploadRules::collection()))->toHaveCount(1)
        ->and(Media::query()->count())->toBe(2);
});

it('pastes a cut file into the current folder', function (): void {
    $root = feActRoot();
    $source = feActFolder($root, 'Source');
    $media = feActFile($source, 'report.pdf');

    $component = feActComponent();
    $component->call('cutSelection', null, $media->id)
        ->call('pasteClipboard')
        ->assertOk();

    expect((int) $media->refresh()->model_id)->toBe((int) $root->id)
        ->and(Media::query()->count())->toBe(1);
});

it('captions the delete entry only when there is something to say', function (): void {
    $media = feActFile(feActRoot(), 'draft.pdf');
    $folder = feActFolder(feActRoot(), 'Archive');

    // Allowed, with no reason behind it. The caption used to read "Deletable",
    // which told the reader nothing the button above it had not — it showed up
    // under every Delete entry in the context menu.
    expect(feActComponent()->instance()->getDeleteState('file', $media->id)['hint'])->toBe('')
        ->and(feActComponent()->instance()->getDeleteState('folder', $folder->id)['hint'])->toBe('');
});

it('keeps the caption when the authorizer has something to say', function (): void {
    $media = feActFile(feActRoot(), 'draft.pdf');

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function mediaDeleteState(string $scopeKey, Media $media): array
        {
            return [
                'allowed' => true,
                'reason_code' => 'window',
                'reason' => 'Deletable for 2 more minutes',
                'remaining_seconds' => 120,
                'window_seconds' => 600,
            ];
        }
    });
    app()->forgetScopedInstances();

    // A reason given *while* allowing — a closing time window — is exactly what
    // the caption is for.
    expect(feActComponent()->instance()->getDeleteState('file', $media->id)['hint'])
        ->toBe('Deletable for 2 more minutes');
});
