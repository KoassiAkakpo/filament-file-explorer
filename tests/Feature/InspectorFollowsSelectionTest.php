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

beforeEach(function (): void {
    Storage::fake('public');
});

function feInRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feInComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feInRoot()->id,
    ]);
}

function feInFolder(string $name): Folder
{
    return Folder::query()->create(['name' => $name, 'slug' => Str::slug($name), 'parent_id' => feInRoot()->id]);
}

function feInMedia(string $name): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => feInRoot()->id,
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
    ]);
}

it('shows the item selected next', function (): void {
    $first = feInMedia('first.pdf');
    $second = feInMedia('second.pdf');

    $component = feInComponent();
    $component->call('setSelection', [], [$first->id])
        ->call('showInfo', 'file', $first->id)
        ->assertSet('infoItem.name', 'first.pdf');

    // The panel used to keep describing the first file for as long as it stayed
    // open, whatever the user clicked next.
    $component->call('setSelection', [], [$second->id])
        ->assertSet('showInfoModal', true)
        ->assertSet('infoItem.name', 'second.pdf')
        ->assertSee('second.pdf');
});

it('follows a selection from a file to a folder', function (): void {
    $file = feInMedia('report.pdf');
    $folder = feInFolder('Invoices');

    $component = feInComponent();
    $component->call('setSelection', [], [$file->id])
        ->call('showInfo', 'file', $file->id);

    $component->call('setSelection', [$folder->id], [])
        ->assertSet('infoItem.type', 'folder')
        ->assertSet('infoItem.name', 'Invoices');
});

it('switches to the summary when a second item joins the selection', function (): void {
    $first = feInMedia('first.pdf');
    $second = feInMedia('second.pdf');

    $component = feInComponent();
    $component->call('setSelection', [], [$first->id])
        ->call('showInfo', 'file', $first->id);

    $component->call('setSelection', [], [$first->id, $second->id])
        ->assertSet('infoItem.type', 'multi')
        // The summary used to be built from hardcoded English, which this change
        // put in front of the user far more often.
        ->assertSet('infoItem.name', '2 items selected')
        ->assertSet('infoItem.mime', 'no folder · 2 files');
});

it('reports the summary through the translator', function (): void {
    // Livewire restores the locale from the snapshot, so it has to be set
    // before the component is mounted.
    app()->setLocale('fr');

    $folder = feInFolder('Invoices');
    $file = feInMedia('report.pdf');

    feInComponent()
        ->call('setSelection', [$folder->id], [$file->id])
        ->call('showInfo')
        ->assertSet('infoItem.name', '2 éléments sélectionnés')
        ->assertSet('infoItem.mime', '1 dossier · 1 fichier');
});

it('closes when the last item is deselected', function (): void {
    $file = feInMedia('report.pdf');

    $component = feInComponent();
    $component->call('setSelection', [], [$file->id])
        ->call('showInfo', 'file', $file->id)
        ->assertSet('showInfoModal', true);

    $component->call('setSelection', [], [])
        ->assertSet('showInfoModal', false)
        ->assertSet('infoItem', null);
});

it('closes when the selection is cleared', function (): void {
    $folder = feInFolder('Invoices');

    $component = feInComponent();
    $component->call('setSelection', [$folder->id], [])
        ->call('showInfo', 'folder', $folder->id);

    $component->call('clearSelection')->assertSet('showInfoModal', false);
});

it('leaves the panel of the current folder alone', function (): void {
    $file = feInMedia('report.pdf');

    // Get Info on empty space describes the folder being browsed: there is no
    // selection behind it to follow, and nothing to close it.
    $component = feInComponent();
    $component->call('showInfo')
        ->assertSet('infoItem.scope', 'current')
        ->assertSet('infoItem.name', feInRoot()->name);

    $component->call('setSelection', [], [$file->id])
        ->assertSet('showInfoModal', true)
        ->assertSet('infoItem.name', feInRoot()->name);

    $component->call('setSelection', [], [])
        ->assertSet('showInfoModal', true);
});

it('stays closed when it was closed', function (): void {
    $file = feInMedia('report.pdf');

    feInComponent()
        ->call('setSelection', [], [$file->id])
        ->assertSet('showInfoModal', false)
        ->assertSet('infoItem', null);
});

it('follows the public selection methods too', function (): void {
    $first = feInMedia('first.pdf');
    $second = feInMedia('second.pdf');

    $component = feInComponent();
    $component->call('selectFile', $first->id)
        ->call('showInfo', 'file', $first->id);

    $component->call('selectFile', $second->id)
        ->assertSet('infoItem.name', 'second.pdf');

    $component->call('selectFile', $second->id, true)
        ->assertSet('showInfoModal', false);
});

it('closes instead of failing when the selected row is gone', function (): void {
    $file = feInMedia('report.pdf');

    $component = feInComponent();
    $component->call('setSelection', [], [$file->id])
        ->call('showInfo', 'file', $file->id);

    // Another session purged it: a plain selection click must not become a 404.
    $file->forceDelete();

    $component->call('setSelection', [], [$file->id])
        ->assertOk()
        ->assertSet('showInfoModal', false);
});

it('closes instead of refusing when the ability is revoked', function (): void {
    $first = feInMedia('first.pdf');
    $second = feInMedia('second.pdf');

    $component = feInComponent();
    $component->call('setSelection', [], [$first->id])
        ->call('showInfo', 'file', $first->id);

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'getInfo' => false];
        }
    });

    // A revocation reaches the explorer on the next request, which is a new
    // container scope: Abilities memoises for one request, so the boundary has
    // to be crossed here the way the browser crosses it.
    app()->forgetScopedInstances();

    $component->call('setSelection', [], [$second->id])
        ->assertOk()
        ->assertSet('showInfoModal', false);
});

it('names a file by its extension rather than by its mime type', function (): void {
    $media = feInMedia('contract.docx');
    $media->mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $media->save();

    $component = feInComponent()->call('showInfo', 'file', $media->id);

    // The office mime types are long enough to push every other row of the
    // panel out of its column, and nobody reads a file's kind that way.
    expect($component->get('infoItem')['mime'])->toBe('DOCX');
});

it('falls back to the mime type for a file with no extension', function (): void {
    $media = feInMedia('LICENSE');
    $media->mime_type = 'text/plain';
    $media->save();

    // Something is better than the em dash the panel would otherwise show.
    expect(feInComponent()->call('showInfo', 'file', $media->id)->get('infoItem')['mime'])
        ->toBe('text/plain');
});
