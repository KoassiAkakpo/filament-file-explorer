<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerEvent;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Events\FileCopied;
use Koassi\FilamentFileExplorer\Events\FileDeleted;
use Koassi\FilamentFileExplorer\Events\FileMoved;
use Koassi\FilamentFileExplorer\Events\FileRenamed;
use Koassi\FilamentFileExplorer\Events\FileRestored;
use Koassi\FilamentFileExplorer\Events\FileTrashed;
use Koassi\FilamentFileExplorer\Events\FileUploaded;
use Koassi\FilamentFileExplorer\Events\FolderCopied;
use Koassi\FilamentFileExplorer\Events\FolderCreated;
use Koassi\FilamentFileExplorer\Events\FolderDeleted;
use Koassi\FilamentFileExplorer\Events\FolderMoved;
use Koassi\FilamentFileExplorer\Events\FolderRenamed;
use Koassi\FilamentFileExplorer\Events\FolderRestored;
use Koassi\FilamentFileExplorer\Events\FolderTrashed;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');

    $this->heard = new ArrayObject;

    // Registered against the marker interface, not a concrete class: this is
    // the "one listener sees everything" contract an audit trail needs, and it
    // only works because Laravel resolves listeners for interfaces.
    Event::listen(FileExplorerEvent::class, fn (object $event) => $this->heard->append($event));
});

function feEvRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feEvComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feEvRoot()->id,
    ]);
}

function feEvFolder(string $name = 'Reports', ?int $parentId = null): Folder
{
    return Folder::query()->create([
        'name' => $name,
        'slug' => Str::slug($name),
        'parent_id' => $parentId ?? feEvRoot()->id,
    ]);
}

function feEvMedia(string $name = 'report.pdf', ?Folder $folder = null): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => ($folder ?? feEvRoot())->id,
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

/**
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return list<T>
 */
function feEvOf(ArrayObject $heard, string $class): array
{
    return array_values(array_filter(iterator_to_array($heard), fn (object $e): bool => $e instanceof $class));
}

it('fires FileUploaded once per file, with the folder it landed in', function (): void {
    feEvComponent()->set('files', [
        UploadedFile::fake()->create('one.pdf', 12, 'application/pdf'),
        UploadedFile::fake()->create('two.pdf', 12, 'application/pdf'),
    ]);

    $events = feEvOf($this->heard, FileUploaded::class);

    expect($events)->toHaveCount(2)
        ->and($events[0]->folder->id)->toBe(feEvRoot()->id)
        ->and(array_map(fn ($e): string => (string) $e->media->file_name, $events))
        ->toEqualCanonicalizing(['one.pdf', 'two.pdf']);
});

it('carries the scope, the root and the actor on every event', function (): void {
    $user = new User(['id' => 7]);

    $this->actingAs($user);

    feEvComponent()->call('createNewFolder')->set('newFolderName', 'Invoices')->call('saveNewFolder');

    $event = feEvOf($this->heard, FolderCreated::class)[0];

    expect($event->scopeKey)->toBe('library')
        ->and($event->rootFolderId)->toBe(feEvRoot()->id)
        ->and($event->actor?->getAuthIdentifier())->toBe(7);
});

it('fires FolderCreated with the folder that was made', function (): void {
    feEvComponent()->call('createNewFolder')->set('newFolderName', 'Invoices')->call('saveNewFolder');

    $events = feEvOf($this->heard, FolderCreated::class);

    expect($events)->toHaveCount(1)
        ->and($events[0]->folder->name)->toBe('Invoices')
        ->and((int) $events[0]->folder->parent_id)->toBe(feEvRoot()->id);
});

it('reports the previous name on a rename', function (): void {
    $folder = feEvFolder('Old name');
    $media = feEvMedia('old.pdf');

    feEvComponent()
        ->call('startRename', 'folder', $folder->id)
        ->set('renameValue', 'New name')
        ->call('saveRename');

    feEvComponent()
        ->call('startRename', 'file', $media->id)
        ->set('renameValue', 'new')
        ->call('saveRename');

    $folderEvent = feEvOf($this->heard, FolderRenamed::class)[0];
    $fileEvent = feEvOf($this->heard, FileRenamed::class)[0];

    expect($folderEvent->previousName)->toBe('Old name')
        ->and($folderEvent->previousSlug)->toBe('old-name')
        ->and($folderEvent->folder->name)->toBe('New name')
        ->and($fileEvent->previousName)->toBe('old.pdf')
        ->and($fileEvent->media->name)->toBe('new');
});

it('reports both ends of a move', function (): void {
    $from = feEvFolder('From');
    $to = feEvFolder('To');
    $moved = feEvFolder('Moved', $from->id);
    $media = feEvMedia('moving.pdf', $from);

    feEvComponent()->call('moveItemsToFolder', $to->id, [$moved->id], [$media->id]);

    $folderEvent = feEvOf($this->heard, FolderMoved::class)[0];
    $fileEvent = feEvOf($this->heard, FileMoved::class)[0];

    expect($folderEvent->fromParentId)->toBe($from->id)
        ->and($folderEvent->toParentId)->toBe($to->id)
        ->and($fileEvent->fromFolderId)->toBe($from->id)
        ->and($fileEvent->toFolderId)->toBe($to->id);
});

it('fires FileCopied for the copy and the source, and FolderCopied once', function (): void {
    $source = feEvFolder('Source');
    $target = feEvFolder('Target');

    // A real file on the fake disk: duplicateMedia copies the bytes.
    feEvComponent()->call('navigateToFolder', $source->id)
        ->set('files', [UploadedFile::fake()->create('inside.pdf', 12, 'application/pdf')]);

    $this->heard->exchangeArray([]);

    feEvComponent()->call('copyItemsToFolder', $target->id, [$source->id], []);

    $folderEvents = feEvOf($this->heard, FolderCopied::class);
    $fileEvents = feEvOf($this->heard, FileCopied::class);

    expect($folderEvents)->toHaveCount(1)
        ->and($folderEvents[0]->source->id)->toBe($source->id)
        ->and($folderEvents[0]->copy->id)->not->toBe($source->id)
        // The descendant file does fire, even though the folder fires only once:
        // every new file row is a new object to index or scan.
        ->and($fileEvents)->toHaveCount(1)
        ->and($fileEvents[0]->source->file_name)->toBe('inside.pdf')
        ->and($fileEvents[0]->copy->id)->not->toBe($fileEvents[0]->source->id);
});

it('fires the trashed events when the trash is on', function (): void {
    $folder = feEvFolder();
    $media = feEvMedia();

    feEvComponent()->call('deleteSelected', [$folder->id], [$media->id]);

    expect(feEvOf($this->heard, FileTrashed::class))->toHaveCount(1)
        ->and(feEvOf($this->heard, FolderTrashed::class))->toHaveCount(1)
        // Nothing was destroyed, so nothing may claim it was.
        ->and(feEvOf($this->heard, FileDeleted::class))->toBeEmpty()
        ->and(feEvOf($this->heard, FolderDeleted::class))->toBeEmpty();
});

it('fires the deleted events with a snapshot when the trash is off', function (): void {
    config(['filament-file-explorer.trash.enabled' => false]);

    $folder = feEvFolder('Doomed');
    $media = feEvMedia('gone.pdf');

    feEvComponent()->call('deleteSelected', [$folder->id], [$media->id]);

    $fileEvent = feEvOf($this->heard, FileDeleted::class)[0];
    $folderEvent = feEvOf($this->heard, FolderDeleted::class)[0];

    // The rows are gone, which is exactly why the payload is a snapshot.
    expect(Media::query()->find($media->id))->toBeNull()
        ->and($fileEvent->mediaId)->toBe($media->id)
        ->and($fileEvent->fileName)->toBe('gone.pdf')
        ->and($fileEvent->folderId)->toBe(feEvRoot()->id)
        ->and($fileEvent->size)->toBe(10)
        ->and($fileEvent->purgedFromTrash)->toBeFalse()
        ->and($folderEvent->folderId)->toBe($folder->id)
        ->and($folderEvent->name)->toBe('Doomed')
        ->and($folderEvent->parentId)->toBe(feEvRoot()->id)
        ->and($folderEvent->purgedFromTrash)->toBeFalse();
});

it('fires the restored events', function (): void {
    $folder = feEvFolder();
    $media = feEvMedia();

    feEvComponent()->call('deleteSelected', [$folder->id], [$media->id]);
    $this->heard->exchangeArray([]);

    feEvComponent()->call('restoreItem', 'folder', $folder->id);
    feEvComponent()->call('restoreItem', 'file', $media->id);

    expect(feEvOf($this->heard, FolderRestored::class))->toHaveCount(1)
        ->and(feEvOf($this->heard, FileRestored::class))->toHaveCount(1);
});

it('marks a purge as coming from the trash', function (): void {
    $media = feEvMedia();

    feEvComponent()->call('deleteSelected', [], [$media->id]);
    $this->heard->exchangeArray([]);

    // requestPurge stores the request on the component, so the confirmation
    // has to reach the same instance.
    feEvComponent()->call('requestPurge', 'file', $media->id)->call('confirmDelete');

    $event = feEvOf($this->heard, FileDeleted::class)[0];

    expect($event->purgedFromTrash)->toBeTrue()
        ->and($event->mediaId)->toBe($media->id);
});

it('reports the file a replacing upload destroyed', function (): void {
    config(['filament-file-explorer.upload.on_conflict' => 'replace']);

    feEvComponent()->set('files', [UploadedFile::fake()->create('same.pdf', 12, 'application/pdf')]);
    $first = Media::query()->where('file_name', 'same.pdf')->firstOrFail();

    $this->heard->exchangeArray([]);

    feEvComponent()->set('files', [UploadedFile::fake()->create('same.pdf', 12, 'application/pdf')]);

    $deleted = feEvOf($this->heard, FileDeleted::class);

    expect($deleted)->toHaveCount(1)
        ->and($deleted[0]->mediaId)->toBe($first->id)
        ->and($deleted[0]->purgedFromTrash)->toBeFalse()
        ->and(feEvOf($this->heard, FileUploaded::class))->toHaveCount(1);
});

it('trashes from the files table instead of destroying, and says so', function (): void {
    Filament\Facades\Filament::setCurrentPanel('admin');

    $media = feEvMedia();

    Livewire::test(FileExplorerFiles::class)
        ->callAction(TestAction::make('delete')->table($media));

    // This used to call $record->delete() straight through, which destroys the
    // row and the bytes even with the trash on: the same "Delete" button meant
    // two different things depending on the page it was pressed from.
    expect(Media::query()->find($media->id))->not->toBeNull()
        ->and(Media::query()->find($media->id)->collection_name)->toBe(Trash::collection())
        ->and(feEvOf($this->heard, FileTrashed::class))->toHaveCount(1)
        ->and(feEvOf($this->heard, FileDeleted::class))->toBeEmpty();
});

it('carries the table page scope on the event it fires', function (): void {
    Filament\Facades\Filament::setCurrentPanel('admin');

    $media = feEvMedia();

    Livewire::test(FileExplorerFiles::class)
        ->callAction(TestAction::make('delete')->table($media));

    $event = feEvOf($this->heard, FileTrashed::class)[0];

    expect($event->scopeKey)->toBe('library')
        ->and($event->rootFolderId)->toBe(feEvRoot()->id);
});
