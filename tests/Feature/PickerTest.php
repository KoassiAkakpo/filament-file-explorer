<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Exceptions\MissingRootFolder;
use Koassi\FilamentFileExplorer\Forms\Components\FileExplorerPicker;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Tests\Fixtures\PickerForm;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    PickerForm::$rootFolderId = null;
    PickerForm::$scopeKey = null;
});

/**
 * The explorer mounts while the field renders, so Blade wraps whatever it throws
 * in a ViewException — the message survives, and it is the message that has to
 * name the cause.
 */
function fePickerThrows(callable $act): Throwable
{
    try {
        $act();
    } catch (Throwable $e) {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e;
    }

    throw new RuntimeException('Nothing was thrown.');
}

it('renders in a form with nothing configured', function (): void {
    // The bug: rootFolderId defaulted to 0, the explorer mounted on folder #0
    // and the findOrFail behind it answered 404 — so adding the field to a
    // create form broke the page rather than the field.
    Livewire::test(PickerForm::class)->assertOk();
});

it('browses the standalone library by default', function (): void {
    Livewire::test(PickerForm::class)->assertOk();

    $picker = FileExplorerPicker::make('files');

    // The library the panel's own explorer page opens: the only root the field
    // can resolve without being told about a record.
    expect($picker->getScopeKey())->toBe('library')
        ->and($picker->getRootFolderId())->toBe(
            (int) FolderModel::query()->whereNull('parent_id')->where('slug', 'library')->value('id')
        );
});

it('creates the standalone root on first use, and only once', function (): void {
    expect(FolderModel::query()->count())->toBe(0);

    $first = FileExplorerPicker::make('files')->getRootFolderId();
    $second = FileExplorerPicker::make('other')->getRootFolderId();

    // ensureRoot() behind it, which is idempotent and locked — a form rendered
    // twice must not leave two libraries.
    expect($first)->toBe($second)
        ->and(FolderModel::query()->count())->toBe(1);
});

it('browses the folder it is given', function (): void {
    $root = app(FileExplorerManager::class)->createRoot('Project 4', 'project-4');

    PickerForm::$rootFolderId = (int) $root->id;
    PickerForm::$scopeKey = 'project.4';

    Livewire::test(PickerForm::class)->assertOk();

    expect(FileExplorerPicker::make('files')->rootFolderId((int) $root->id)->scopeKey('project.4')->getRootFolderId())
        ->toBe((int) $root->id);
});

it('keeps the scope key it had when only the root is named', function (): void {
    $root = app(FileExplorerManager::class)->createRoot('Project 4', 'project-4');

    // The two halves have to describe the same library — abilities are decided
    // per (scope, root) — so naming one must not make the field infer the other
    // from somewhere else entirely.
    expect(FileExplorerPicker::make('files')->rootFolderId((int) $root->id)->getScopeKey())->toBe('picker');
});

it('says what is missing rather than answering 404', function (): void {
    PickerForm::$scopeKey = 'project.4';

    // A root the host never configured is a misconfiguration, not a page that
    // is not there — and the message has to name the field and the fix.
    $thrown = fePickerThrows(fn () => Livewire::test(PickerForm::class));

    expect($thrown)->toBeInstanceOf(MissingRootFolder::class)
        ->and($thrown->getMessage())->toContain('without a root folder (scope "project.4")')
        ->and($thrown->getMessage())->toContain('->rootFolderId($id)');
});

it('says so for a root that has been deleted for good', function (): void {
    $root = app(FileExplorerManager::class)->createRoot('Gone', 'gone');
    $id = (int) $root->id;
    $root->forceDelete();

    PickerForm::$rootFolderId = $id;
    PickerForm::$scopeKey = 'gone';

    $thrown = fePickerThrows(fn () => Livewire::test(PickerForm::class));

    expect($thrown)->toBeInstanceOf(MissingRootFolder::class)
        ->and($thrown->getMessage())->toContain('folder #'.$id)
        ->and($thrown->getMessage())->toContain('does not exist');
});

it('puts the explorer in the modal it renders', function (): void {
    $html = Livewire::test(PickerForm::class)->assertOk()->html();

    // In a modal, and marked as being in one: .fe-in-modal is what lowers the
    // height token, since the modal spends room on a heading and scrolls itself.
    expect($html)->toContain('fe-in-modal')
        ->and($html)->toContain(__('filament-file-explorer::file-explorer.browse_files'));
});
