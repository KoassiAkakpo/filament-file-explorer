<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer as FileExplorerPage;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

/**
 * Flattens a nested translation array to dotted keys, so two locales can be
 * compared as flat sets rather than by structure.
 */
function flattenTranslationKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = array_merge($keys, flattenTranslationKeys($value, $dotted));

            continue;
        }

        $keys[] = $dotted;
    }

    return $keys;
}

function translationKeysFor(string $locale): array
{
    $keys = flattenTranslationKeys(require __DIR__."/../../resources/lang/{$locale}/file-explorer.php");
    sort($keys);

    return $keys;
}

it('keeps every locale structurally identical', function (): void {
    // A key added to one locale only would silently fall back to the raw
    // dotted key in the UI, so parity is asserted rather than assumed.
    expect(translationKeysFor('fr'))->toBe(translationKeysFor('en'));
});

it('boots the explorer component in the given locale', function (string $locale): void {
    app()->setLocale($locale);

    $page = new FileExplorerPage;
    $page->mount();

    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $page->fileExplorerScopeKey(),
        'rootFolderId' => $page->rootFolderId,
    ])->assertOk();
})->with(['en', 'fr']);

it('localizes the toolbar, sort menus and context menu', function (): void {
    app()->setLocale('fr');

    $page = new FileExplorerPage;
    $page->mount();

    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $page->fileExplorerScopeKey(),
        'rootFolderId' => $page->rootFolderId,
    ])
        ->assertSee('Trier par')
        ->assertSee('Icônes', escape: false)
        ->assertSee('Compresser en ZIP')
        ->assertSee('Ouvrir dans un nouvel onglet')
        ->assertSee('Date de modification')
        // These labels were once hardcoded in the Blade view next to the
        // translation keys that went unread; they must not reappear.
        ->assertDontSee('Sort By')
        ->assertDontSee('Compress to ZIP')
        ->assertDontSee('Open in New Tab')
        ->assertDontSee('Date Modified');
});

it('localizes the inspector panel instead of pinning it to english', function (): void {
    app()->setLocale('fr');

    $page = new FileExplorerPage;
    $page->mount();

    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $page->fileExplorerScopeKey(),
        'rootFolderId' => $page->rootFolderId,
    ])
        ->call('showInfo')
        ->assertOk()
        ->assertSee('Emplacement')
        ->assertSee('Autorisations')
        ->assertDontSee('Permissions')
        ->assertDontSee('Where');
});

it('reports the folder kind through the translator', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    $page = new FileExplorerPage;
    $page->mount();

    $component = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $page->fileExplorerScopeKey(),
        'rootFolderId' => $page->rootFolderId,
    ]);

    $component->call('showInfo');

    expect($component->get('infoItem')['mime'])->toBe($expected);
})->with([
    ['en', 'Current folder'],
    ['fr', 'Dossier courant'],
]);

it('pluralizes folder item counts per locale', function (string $locale, string $expected): void {
    // The locale has to be set before the component mounts: Livewire's test
    // harness rebuilds the request on every call(), which resets it.
    app()->setLocale($locale);

    $page = new FileExplorerPage;
    $page->mount();

    $component = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $page->fileExplorerScopeKey(),
        'rootFolderId' => $page->rootFolderId,
    ]);

    $component->call('createNewFolder')
        ->set('newFolderName', 'Docs')
        ->call('saveNewFolder');

    $folder = Folder::where('name', 'Docs')->sole();

    $component->call('showInfo', 'folder', $folder->id);

    expect($component->get('infoItem')['extra'])->toBe($expected);
})->with([
    ['en', '0 items'],
    ['fr', '0 élément'],
]);

it('renders the search header without duplicating the result count', function (): void {
    $page = new FileExplorerPage;
    $page->mount();

    $component = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $page->fileExplorerScopeKey(),
        'rootFolderId' => $page->rootFolderId,
    ]);

    $component->call('createNewFolder')
        ->set('newFolderName', 'Docs')
        ->call('saveNewFolder');

    // The count used to be printed by the view *and* by trans_choice's
    // :count replacement, rendering "1 1 result".
    $component->set('search', 'Docs')
        ->assertOk()
        ->assertSee('1 result')
        ->assertDontSee('1 1 result');
});

it('builds the upload notification body from the translator', function (string $locale, int $count, string $expected): void {
    app()->setLocale($locale);

    // This body was once concatenated by hand and had lost its opening
    // quotation mark, rendering "3 Docs»".
    expect(trans_choice(
        'filament-file-explorer::file-explorer.uploaded_to_folder',
        $count,
        ['folder' => 'Docs'],
    ))->toBe($expected);
})->with([
    ['en', 1, '1 file in “Docs”'],
    ['en', 3, '3 files in “Docs”'],
    ['fr', 1, '1 fichier dans « Docs »'],
    ['fr', 3, '3 fichiers dans « Docs »'],
]);
