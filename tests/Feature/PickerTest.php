<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Exceptions\MissingRootFolder;
use Koassi\FilamentFileExplorer\Forms\Components\FileExplorerPicker;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\FileKinds;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\PickerForm;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
    PickerForm::$rootFolderId = null;
    PickerForm::$scopeKey = null;
    PickerForm::$multiple = false;
    PickerForm::$kinds = [];
});

function fePickerRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function fePickerMedia(string $name, ?int $folderId = null, string $mime = 'application/pdf'): Media
{
    return Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => $folderId ?? fePickerRootId(),
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => $mime,
        'disk' => 'public',
        'size' => 8,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

function fePickerExplorer(string $token = 'data.files', bool $multiple = false, array $kinds = []): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => fePickerRootId(),
        'pickerToken' => $token,
        'pickMultiple' => $multiple,
        'pickKinds' => $kinds,
    ]);
}

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

it('opens the modal through the contract the modal actually has', function (): void {
    $html = Livewire::test(PickerForm::class)->assertOk()->html();

    // Filament's modal keeps its own state and opens on a windowed `open-modal`
    // carrying its id. The button used to flip an x-data flag of ours instead,
    // and lose: an outer x-show on the modal root fought the x-show="isOpen"
    // already there, so the click did nothing at all — no modal, no error.
    expect($html)->toContain('$dispatch(\'open-modal\', { id: \'file-explorer-picker-form.files\' })')
        ->toContain('fi-modal-trigger')
        ->toContain('filamentModal(');
});

it('does not hide the modal window it just opened', function (): void {
    // :visible="false" put fi-hidden on .fi-modal-window, so the window stayed
    // hidden even once the overlay opened. It is not the prop for "closed" —
    // the modal is closed until told otherwise.
    expect(Livewire::test(PickerForm::class)->assertOk()->html())->not->toContain('fi-hidden');
});

it('leaves the modal its own state to keep', function (): void {
    $template = (string) file_get_contents(
        __DIR__.'/../../resources/views/filament/forms/file-explorer-picker.blade.php'
    );

    // Comments stripped first, or this trips on the note explaining the very
    // mistake it guards: the rule is about the directives, not the prose.
    $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $template);

    // Scoped to the modal's own tag, and that is the whole rule: the field is
    // free to keep Alpine state of its own — the pick listener needs a scope —
    // as long as none of it lands on the element that already has state.
    preg_match('/<x-filament::modal\b[^>]*>/', $markup, $tag);

    expect($tag)->not->toBeEmpty()
        ->and($tag[0])->not->toContain('x-show')
        ->and($tag[0])->not->toContain(':visible')
        ->and($tag[0])->not->toContain('x-data');

    // And the state it does keep is the pick listener, not the modal's.
    expect($markup)->not->toContain('open = true')
        ->and($markup)->not->toContain('open: false');
});

/* --------------------------------------------------------------- the picking */

it('hands the chosen file back to the field that asked', function (): void {
    $file = fePickerMedia('lease.pdf');

    fePickerExplorer()
        ->call('setSelection', [], [$file->id])
        ->call('pickSelected')
        // The token is the field's state path, so a page holding two pickers
        // has each one hear only its own.
        ->assertDispatched('fe-picked', token: 'data.files', files: [(int) $file->id]);
});

it('chooses one file when the field is single, whatever is selected', function (): void {
    $first = fePickerMedia('a.pdf');
    $second = fePickerMedia('b.pdf');

    // The first of the selection rather than a refusal: someone who lassoed
    // three files and pressed Choose meant to choose, and an error would be the
    // field blaming them for its own arity.
    fePickerExplorer()
        ->call('setSelection', [], [$second->id, $first->id])
        ->call('pickSelected')
        ->assertDispatched('fe-picked', files: [(int) $second->id]);
});

it('chooses them all when the field is multiple', function (): void {
    $first = fePickerMedia('a.pdf');
    $second = fePickerMedia('b.pdf');

    fePickerExplorer(multiple: true)
        ->call('setSelection', [], [$first->id, $second->id])
        ->call('pickSelected')
        ->assertDispatched('fe-picked', files: [(int) $first->id, (int) $second->id]);
});

it('chooses files and never the folders beside them', function (): void {
    $file = fePickerMedia('lease.pdf');
    $folder = app(FileExplorerManager::class)->createChild(
        FolderModel::query()->findOrFail(fePickerRootId()),
        'Contracts',
    );

    // A folder is where you look, not what you choose.
    fePickerExplorer(multiple: true)
        ->call('setSelection', [(int) $folder->id], [$file->id])
        ->call('pickSelected')
        ->assertDispatched('fe-picked', files: [(int) $file->id]);
});

it('says nothing when nothing is selected', function (): void {
    fePickerExplorer()->call('pickSelected')->assertNotDispatched('fe-picked');
});

it('says nothing at all when it is not a picker', function (): void {
    $file = fePickerMedia('lease.pdf');

    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => fePickerRootId(),
    ])
        ->call('setSelection', [], [$file->id])
        ->call('pickSelected')
        ->assertNotDispatched('fe-picked');
});

it('proves containment again before an id leaves the explorer', function (): void {
    $outside = app(FileExplorerManager::class)->createRoot('Elsewhere', 'elsewhere');
    $stranger = fePickerMedia('theirs.pdf', (int) $outside->id);
    $mine = fePickerMedia('mine.pdf');

    // selectFile() is public API and deliberately not narrowed, so this is the
    // one place an id leaves for the host's own state — containment is proved
    // here rather than assumed from the selection.
    fePickerExplorer(multiple: true)
        ->call('selectFile', $mine->id)
        ->call('selectFile', $stranger->id, true)
        ->call('pickSelected')
        ->assertDispatched('fe-picked', files: [(int) $mine->id]);
});

it('refuses to pick for someone who cannot browse', function (): void {
    $file = fePickerMedia('lease.pdf');

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return array_merge(array_fill_keys(Abilities::ORIGINAL, true), ['browse' => false]);
        }
    });
    app()->forgetScopedInstances();

    fePickerExplorer()->call('setSelection', [], [$file->id])->call('pickSelected')->assertForbidden();
});

it('offers the choose button only when it is a picker', function (): void {
    $label = __('filament-file-explorer::file-explorer.picker.choose');

    $toolbar = fn (Testable $c): string => (string) Str::between($c->html(), 'class="fe-toolbar', 'class="fe-browser');

    expect($toolbar(fePickerExplorer()))->toContain($label)
        ->and($toolbar(Livewire::test(FileExplorerComponent::class, [
            'scopeKey' => 'library',
            'rootFolderId' => fePickerRootId(),
        ])))->not->toContain($label);
});

it('flushes the debounced selection before it asks the server', function (): void {
    $html = fePickerExplorer()->html();

    // The mirror is debounced by 40ms, so clicking a file and Choose in the same
    // breath would otherwise hand the server the selection from before the
    // click. Livewire keeps the two calls in order.
    expect($html)->toContain('sel.flushSync(); $wire.pickSelected()');
});

/* ------------------------------------------------------------- field state */

it('holds one id when single and a list when multiple', function (): void {
    $first = fePickerMedia('a.pdf');
    $second = fePickerMedia('b.pdf');

    $single = FileExplorerPicker::make('files');
    $many = FileExplorerPicker::make('files')->multiple();

    expect($single->normaliseState([$first->id, $second->id]))->toBe((int) $first->id)
        ->and($single->normaliseState(null))->toBeNull()
        ->and($many->normaliseState($first->id))->toBe([(int) $first->id])
        ->and($many->normaliseState(null))->toBe([]);
});

it('draws what it holds, in the order it was chosen', function (): void {
    $first = fePickerMedia('a.pdf');
    $second = fePickerMedia('b.pdf');

    PickerForm::$multiple = true;

    $html = Livewire::test(PickerForm::class)
        ->set('data.files', [(int) $second->id, (int) $first->id])
        ->assertOk()
        ->html();

    // Drawn from the state and not from the explorer's selection: the two are
    // different things, and a chosen file may live in a folder the explorer is
    // not showing.
    expect($html)->toContain('fe-picker__list')
        ->and(strpos($html, 'b.pdf'))->toBeLessThan((int) strpos($html, 'a.pdf'));
});

it('draws nothing for an id that is not this scope\'s', function (): void {
    $outside = app(FileExplorerManager::class)->createRoot('Elsewhere', 'elsewhere');
    $stranger = fePickerMedia('theirs.pdf', (int) $outside->id);

    $html = Livewire::test(PickerForm::class)
        ->set('data.files', (int) $stranger->id)
        ->assertOk()
        ->html();

    // A name is information. An id the state carries from somewhere else draws
    // nothing rather than leaking one the viewer may not be allowed to see.
    expect($html)->not->toContain('theirs.pdf');
});

it('listens for its own token and closes its own modal', function (): void {
    $html = Livewire::test(PickerForm::class)->assertOk()->html();

    expect($html)->toContain('$event.detail.token !== \'data.files\'')
        ->and($html)->toContain('$dispatch(\'close-modal\', { id: \'file-explorer-picker-form.files\' })');
});

it('lands in the form state, single and multiple', function (): void {
    $first = fePickerMedia('a.pdf');
    $second = fePickerMedia('b.pdf');

    // The whole round trip, minus the browser hop: the explorer names the
    // files, the field's listener writes them at its own state path. Asserting
    // the two halves meet is the only way to know the field is a field.
    $picked = fePickerExplorer(multiple: true)
        ->call('setSelection', [], [$first->id, $second->id])
        ->call('pickSelected')
        ->assertDispatched('fe-picked');

    PickerForm::$multiple = true;

    $form = Livewire::test(PickerForm::class)
        ->set('data.files', [(int) $first->id, (int) $second->id])
        ->assertOk();

    expect($form->get('data.files'))->toBe([(int) $first->id, (int) $second->id])
        ->and($form->html())->toContain('a.pdf')
        ->and($form->html())->toContain('b.pdf');

    PickerForm::$multiple = false;

    $single = Livewire::test(PickerForm::class)->set('data.files', (int) $second->id)->assertOk();

    expect($single->get('data.files'))->toBe((int) $second->id)
        ->and($single->html())->toContain('b.pdf')
        ->and($single->html())->not->toContain('a.pdf');

    expect($picked)->not->toBeNull();
});

it('empties to a shape the form can save', function (): void {
    PickerForm::$multiple = true;

    // Dehydrated through the same normalisation, so a form saving an empty
    // multiple picker writes [] rather than null, and an empty single one writes
    // null rather than [] — whichever the column expects, it is not a surprise.
    expect(FileExplorerPicker::make('files')->multiple()->normaliseState(['', 0, null]))->toBe([])
        ->and(FileExplorerPicker::make('files')->normaliseState([0]))->toBeNull();
});

/* ------------------------------------------------------- restricting the kind */

it('shows only the kinds it accepts', function (): void {
    $image = fePickerMedia('logo.png', mime: 'image/png');
    $pdf = fePickerMedia('lease.pdf');
    $sheet = fePickerMedia('budget.csv', mime: 'text/csv');
    $clip = fePickerMedia('demo.mp4', mime: 'video/mp4');

    $listing = fePickerExplorer(kinds: ['image', 'pdf'])->instance()->listing();

    // You cannot pick what you cannot see, which is why the restriction narrows
    // the listing and not only the pick — and it runs before the window and the
    // counts, so "2 of 2" describes what is left.
    expect($listing['files']->pluck('id')->all())->toBe([(int) $pdf->id, (int) $image->id])
        ->and($listing['total'])->toBe(2);

    expect([(int) $sheet->id, (int) $clip->id])->not->toContain(...$listing['files']->pluck('id')->all());
});

it('leaves the folders alone when it narrows the kinds', function (): void {
    app(FileExplorerManager::class)->createChild(
        FolderModel::query()->findOrFail(fePickerRootId()),
        'Contracts',
    );
    fePickerMedia('demo.mp4', mime: 'video/mp4');

    // Folders have no kind, and hiding them would filter the user into a room
    // with no doors: the one thing still needed is a way to look elsewhere.
    expect(fePickerExplorer(kinds: ['image'])->instance()->listing()['folders']->count())->toBe(1);
});

it('offers only the accepted kinds in the filter menu', function (): void {
    $menu = fePickerExplorer(kinds: ['image', 'pdf'])->instance()->kindOptions();

    // An entry that can only ever come back empty is worse than no entry: it
    // reads as a folder holding nothing rather than as a kind not taken here.
    expect($menu)->toBe(['image', 'pdf'])
        ->and(fePickerExplorer()->instance()->kindOptions())->toBe(FileKinds::all());
});

it('ignores a filter for a kind it does not accept', function (): void {
    $image = fePickerMedia('logo.png', mime: 'image/png');

    $component = fePickerExplorer(kinds: ['image'])->call('setKind', 'video');

    // Not an empty folder with a filter to blame that the menu never offered.
    expect($component->get('kind'))->toBeNull()
        ->and($component->instance()->listing()['files']->pluck('id')->all())->toBe([(int) $image->id]);
});

it('still filters within the kinds it accepts', function (): void {
    $image = fePickerMedia('logo.png', mime: 'image/png');
    fePickerMedia('lease.pdf');

    $component = fePickerExplorer(kinds: ['image', 'pdf'])->call('setKind', 'image');

    expect($component->instance()->listing()['files']->pluck('id')->all())->toBe([(int) $image->id]);
});

it('refuses to pick a file of a kind it does not accept', function (): void {
    $image = fePickerMedia('logo.png', mime: 'image/png');
    $clip = fePickerMedia('demo.mp4', mime: 'video/mp4');

    // selectFile() is public API and not narrowed, so a kind the field refuses
    // can still be selected — and must not survive the pick. Same predicate as
    // the listing, run in SQL, so the two cannot disagree.
    fePickerExplorer(multiple: true, kinds: ['image'])
        ->call('selectFile', $image->id)
        ->call('selectFile', $clip->id, true)
        ->call('pickSelected')
        ->assertDispatched('fe-picked', files: [(int) $image->id]);
});

it('says nothing when everything selected is of the wrong kind', function (): void {
    $clip = fePickerMedia('demo.mp4', mime: 'video/mp4');

    fePickerExplorer(kinds: ['image'])
        ->call('selectFile', $clip->id)
        ->call('pickSelected')
        ->assertNotDispatched('fe-picked');
});

it('cannot have its restriction lifted from the browser', function (): void {
    // A public Livewire property is the client's own state, and a limit the
    // client can empty is not a limit — hence #[Locked].
    expect(fn () => fePickerExplorer(kinds: ['image'])->set('pickKinds', []))
        ->toThrow('Cannot update locked property: [pickKinds]');
});

it('refuses a kind that does not exist, when the field is written', function (): void {
    // Thrown rather than dropped: a typo that silently narrows nothing is a
    // restriction that quietly does not restrict.
    expect(fn () => FileExplorerPicker::make('files')->kinds('imgae'))
        ->toThrow(InvalidArgumentException::class, 'Unknown file kind(s) imgae');

    expect(FileExplorerPicker::make('files')->kinds('image')->getKinds())->toBe(['image'])
        ->and(FileExplorerPicker::make('files')->kinds(['pdf', 'image'])->getKinds())->toBe(['image', 'pdf']);
});

it('will not save an id of a kind it does not accept', function (): void {
    $image = fePickerMedia('logo.png', mime: 'image/png');
    $clip = fePickerMedia('demo.mp4', mime: 'video/mp4');

    $field = FileExplorerPicker::make('files')->multiple()->kinds(['image']);

    // The state is written from the browser, so the explorer's guard is only the
    // happy path: nothing stops a crafted request setting the state path to any
    // id at all. This is the half that decides what gets saved.
    expect($field->idsOutsideScope([(int) $image->id, (int) $clip->id]))->toBe([(int) $clip->id])
        ->and($field->idsOutsideScope([(int) $image->id]))->toBe([])
        ->and($field->idsOutsideScope(null))->toBe([]);
});

it('draws nothing for a held id of the wrong kind', function (): void {
    $clip = fePickerMedia('demo.mp4', mime: 'video/mp4');

    PickerForm::$kinds = ['image'];

    expect(Livewire::test(PickerForm::class)->set('data.files', (int) $clip->id)->assertOk()->html())
        ->not->toContain('demo.mp4');
});
