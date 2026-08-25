<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Events\FileDeleted;
use Koassi\FilamentFileExplorer\Events\FileVersioned;
use Koassi\FilamentFileExplorer\Events\FileVersionRestored;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\FileVersion;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\Annotations;
use Koassi\FilamentFileExplorer\Support\FolderListing;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Support\Versions;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');

    // Versioning only ever engages on this policy, so every test here sets it:
    // the package's own default is 'rename', which keeps both files instead.
    config(['filament-file-explorer.upload.on_conflict' => 'replace']);
});

function feVeRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feVeComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feVeRootId(),
    ]);
}

/**
 * An upload that really weighs $kb kilobytes.
 *
 * UploadedFile::fake()->create() fakes getSize() and writes an empty file, so
 * nothing measuring bytes — the quota, a version's size in the panel — could be
 * tested with it at all.
 */
function feVeFile(string $name = 'report.pdf', int $kb = 4): UploadedFile
{
    $header = '%PDF-1.4'."\n";

    return UploadedFile::fake()->createWithContent($name, $header.str_repeat('0', max(1, $kb * 1024 - strlen($header))));
}

/**
 * Uploads over the same name $times times and returns the row now live.
 */
function feVeUpload(string $name = 'report.pdf', int $kb = 4): Media
{
    feVeComponent()->set('files', [feVeFile($name, $kb)]);

    return Media::query()
        ->where('collection_name', UploadRules::collection())
        ->where('file_name', $name)
        ->orderByDesc('id')
        ->firstOrFail();
}

function feVeLive(): int
{
    return Media::query()->where('collection_name', UploadRules::collection())->count();
}

function feVeKept(): int
{
    return Media::query()->where('collection_name', Versions::collection())->count();
}

/* ------------------------------------------------- moving aside, not destroying */

it('keeps the file a replacement would have destroyed', function (): void {
    $first = feVeUpload();
    $second = feVeUpload();

    expect($second->id)->not->toBe($first->id)
        ->and($first->refresh()->collection_name)->toBe(Versions::collection())
        ->and($second->refresh()->collection_name)->toBe(UploadRules::collection());

    // The bytes are where they always were: a version costs a column write, not
    // a copy, which is the same trade the trash makes.
    expect(Storage::disk('public')->exists($first->id.'/'.$first->file_name))->toBeTrue();
});

it('leaves one file in the folder, whatever the history behind it', function (): void {
    feVeUpload();
    feVeUpload();
    feVeUpload();

    $listing = (new FolderListing(feVeRootId(), feVeRootId()))->window(50);

    // Every listing already filters on the explorer's collection, which is the
    // reason a version needed no new filter anywhere: it is out of that
    // collection, exactly like a trashed file.
    expect($listing['files'])->toHaveCount(1)
        ->and($listing['total'])->toBe(1)
        ->and(feVeLive())->toBe(1)
        ->and(feVeKept())->toBe(2);
});

it('keeps a version out of the media routes', function (): void {
    $first = feVeUpload();
    feVeUpload();

    // A version is not a second way to download a file. The containment check
    // requires the live collection, so it refuses this for the same reason it
    // refuses a trashed file.
    $this->actingAs(new User(['id' => 1]))
        ->get(route('filament-file-explorer.media.show', ['scopeKey' => 'library', 'media' => $first->id]))
        ->assertForbidden();
});

it('keeps a version out of the trash', function (): void {
    feVeUpload();
    feVeUpload();

    // A version is not a deleted file, and the trash view is where deleting is
    // undone. Free, because the trash lists its own collection.
    expect(app(Trash::class)->count(feVeRootId()))->toBe(0);
});

it('refuses to share the versions collection with the live one', function (): void {
    config(['filament-file-explorer.versions.collection' => UploadRules::collection()]);

    // Guarded rather than documented: a config file is not the place to learn
    // that this would make the versions part of the folder they are the history
    // of, and the trash's collection would put them in the trash view.
    expect(Versions::collection())->toBe(UploadRules::collection().'-versions');

    config(['filament-file-explorer.versions.collection' => Trash::collection()]);

    expect(Versions::collection())->toBe(UploadRules::collection().'-versions');
});

/* ------------------------------------------------------------------- the lineage */

it('keeps the history through a rename', function (): void {
    feVeUpload();
    $live = feVeUpload();

    feVeComponent()
        ->call('startRename', 'file', $live->id)
        ->set('renameValue', 'contract')
        ->call('saveRename');

    $renamed = Media::query()->findOrFail($live->id);
    $history = app(Versions::class)->history((int) $renamed->id);

    // The chain is a lineage minted once, not the folder and the file name: a
    // rename would have cut it in half, and carrying it across the rename would
    // have been one more place to keep in step.
    expect($history)->toHaveCount(1);
});

it('keeps the history through a move', function (): void {
    feVeUpload();
    $live = feVeUpload();

    $target = FolderModel::query()->create([
        'name' => 'Archive',
        'slug' => 'archive',
        'parent_id' => feVeRootId(),
    ]);

    feVeComponent()->call('moveItemsToFolder', (int) $target->id, [], [$live->id]);

    expect(app(Versions::class)->history((int) $live->id))->toHaveCount(1);
});

it('numbers the versions in the order they were replaced', function (): void {
    feVeUpload();
    feVeUpload();
    $third = feVeUpload();

    $history = app(Versions::class)->history((int) $third->id);

    expect(array_column($history, 'sequence'))->toBe([2, 1]);
});

it('purges past the number it keeps', function (): void {
    config(['filament-file-explorer.versions.keep' => 2]);

    $first = feVeUpload();
    feVeUpload();
    feVeUpload();
    $live = feVeUpload();

    // The ceiling is a configured number rather than a judgement made in the
    // code: this is the one place in the feature where bytes are lost.
    expect(app(Versions::class)->history((int) $live->id))->toHaveCount(2)
        ->and(Media::query()->find($first->id))->toBeNull()
        ->and(FileVersion::query()->where('media_id', $first->id)->exists())->toBeFalse()
        ->and(Storage::disk('public')->exists($first->id.'/'.$first->file_name))->toBeFalse();
});

it('never keeps nothing', function (): void {
    config(['filament-file-explorer.versions.keep' => 0]);

    // A history that keeps nothing is the feature turned off, and `enabled` is
    // what says that.
    expect(Versions::keep())->toBe(1);
});

/* --------------------------------------------------------------------- the quota */

it('counts what the versions occupy', function (): void {
    $first = feVeUpload('report.pdf', 8);
    $second = feVeUpload('report.pdf', 8);

    // They sit on the disk until something purges them, exactly like a trashed
    // file — an allowance that stopped counting them would be a way over the
    // cap: upload over the same name, repeat.
    expect(app(Quota::class)->usedBytes(feVeRootId()))
        ->toBe((int) $first->refresh()->size + (int) $second->refresh()->size)
        ->and(app(Quota::class)->fileCount(feVeRootId()))->toBe(2);
});

it('charges a replacement the whole incoming size when it keeps the old one', function (): void {
    config(['filament-file-explorer.quota.bytes' => 12 * 1024]);

    feVeUpload('report.pdf', 8);
    feVeComponent()->set('files', [feVeFile('report.pdf', 8)]);

    // Replacing frees nothing here, so the difference is not what counts. The
    // second upload does not fit and is refused rather than silently putting the
    // scope over its cap.
    expect(feVeLive())->toBe(1)
        ->and(feVeKept())->toBe(0)
        ->and(app(Quota::class)->usedBytes(feVeRootId()))->toBeLessThanOrEqual(12 * 1024);
});

it('charges only the difference when the old file really is destroyed', function (): void {
    config([
        'filament-file-explorer.versions.enabled' => false,
        'filament-file-explorer.quota.bytes' => 12 * 1024,
    ]);

    feVeUpload('report.pdf', 8);
    feVeUpload('report.pdf', 8);

    // Which is the behaviour that was there before versions: the replaced file's
    // bytes are freed, so the same upload fits.
    expect(feVeLive())->toBe(1)
        ->and(feVeKept())->toBe(0);
});

/* -------------------------------------------------------------------- the events */

it('reports a replacement as a version rather than a deletion', function (): void {
    Event::fake([FileVersioned::class, FileDeleted::class]);

    $first = feVeUpload();
    $second = feVeUpload();

    // FileDeleted used to fire here because replacing really did destroy the old
    // file. It is a lie now, and a listener watching for deletions must not be
    // told a file was lost when its bytes are one click away.
    Event::assertNotDispatched(FileDeleted::class);
    Event::assertDispatched(FileVersioned::class, function (FileVersioned $event) use ($first, $second): bool {
        return $event->versionedMediaId === (int) $first->id
            && (int) $event->media->id === (int) $second->id
            && $event->lineage !== '';
    });
});

it('still reports a deletion when nothing is kept', function (): void {
    config(['filament-file-explorer.versions.enabled' => false]);

    Event::fake([FileVersioned::class, FileDeleted::class]);

    $first = feVeUpload();
    feVeUpload();

    Event::assertNotDispatched(FileVersioned::class);
    Event::assertDispatched(FileDeleted::class, fn (FileDeleted $e): bool => $e->mediaId === (int) $first->id);
});

it('reports a restore', function (): void {
    $first = feVeUpload();
    $second = feVeUpload();

    Event::fake([FileVersionRestored::class]);

    feVeComponent()->call('restoreVersion', $first->id);

    Event::assertDispatched(
        FileVersionRestored::class,
        fn (FileVersionRestored $e): bool => (int) $e->media->id === (int) $first->id
            && $e->replacedMediaId === (int) $second->id,
    );
});

/* ---------------------------------------------------------------- annotations */

it('carries the description and the tags onto the new row', function (): void {
    $first = feVeUpload();

    app(Annotations::class)->setDescription(Annotations::FILE, (int) $first->id, 'The signed one');
    app(Annotations::class)->attach('library', Annotations::FILE, (int) $first->id, 'Urgent');

    $second = feVeUpload();

    // A lineage is one file, and what was written about it goes on being true of
    // it. Without this, `replace` dropped both — and with the old row kept alive
    // it would strand them on a row nothing on any screen can reach, which is
    // worse than losing them.
    expect(app(Annotations::class)->description(Annotations::FILE, (int) $second->id))->toBe('The signed one')
        ->and(array_column(app(Annotations::class)->tags(Annotations::FILE, (int) $second->id), 'name'))->toBe(['Urgent'])
        ->and(app(Annotations::class)->description(Annotations::FILE, (int) $first->id))->toBe('');
});

/* --------------------------------------------------------------------- cleanup */

it('destroys the history with the file', function (): void {
    $first = feVeUpload();
    $live = feVeUpload();

    // Trashed first and then purged: destroyed for good is the state this is
    // about, and with the trash on that takes two steps.
    feVeComponent()->call('deleteSelected', [], [$live->id]);
    feVeComponent()->call('requestPurge', 'file', $live->id)->call('confirmDelete');

    // Through a model listener rather than a call at each delete site, for the
    // reason the annotations use one: four doors delete a file, and one that
    // forgot would leave bytes nothing on any screen accounts for.
    expect(Media::query()->find($first->id))->toBeNull()
        ->and(FileVersion::query()->count())->toBe(0)
        ->and(Storage::disk('public')->exists($first->id.'/'.$first->file_name))->toBeFalse();
});

it('destroys the history when the folder holding it is purged', function (): void {
    $folder = FolderModel::query()->create([
        'name' => 'Contracts',
        'slug' => 'contracts',
        'parent_id' => feVeRootId(),
    ]);

    feVeComponent()->call('navigateToFolder', (int) $folder->id)->set('files', [feVeFile()]);
    feVeComponent()->call('navigateToFolder', (int) $folder->id)->set('files', [feVeFile()]);

    expect(feVeKept())->toBe(1);

    app(Trash::class)->purgeFolder($folder);

    expect(Media::query()->count())->toBe(0)
        ->and(FileVersion::query()->count())->toBe(0);
});

it('keeps the history while the file only sits in the trash', function (): void {
    feVeUpload();
    $live = feVeUpload();

    feVeComponent()->call('deleteSelected', [], [$live->id]);

    // Trashing is not deleting, and a file has to come back with its history.
    expect(Media::query()->findOrFail($live->id)->collection_name)->toBe(Trash::collection())
        ->and(FileVersion::query()->count())->toBe(2)
        ->and(feVeKept())->toBe(1);
});

/* --------------------------------------------------------------------- restore */

it('swaps a version back into the folder', function (): void {
    $first = feVeUpload();
    $second = feVeUpload();

    feVeComponent()->call('restoreVersion', $first->id);

    // The same trade in the other direction: what was current becomes the newest
    // entry of the history rather than being destroyed.
    expect(Media::query()->findOrFail($first->id)->collection_name)->toBe(UploadRules::collection())
        ->and(Media::query()->findOrFail($second->id)->collection_name)->toBe(Versions::collection())
        ->and(feVeLive())->toBe(1)
        ->and(app(Versions::class)->history((int) $first->id))->toHaveCount(1);
});

it('gives the restored row the name the file goes by', function (): void {
    feVeUpload();
    $live = feVeUpload();

    feVeComponent()
        ->call('startRename', 'file', $live->id)
        ->set('renameValue', 'contract')
        ->call('saveRename');

    $renamed = Media::query()->findOrFail($live->id);
    $version = Media::query()->where('collection_name', Versions::collection())->firstOrFail();

    feVeComponent()->call('restoreVersion', $version->id);

    // A lineage is one file, and its name is the live one: restoring last week's
    // content must not rename the file back to what it was called then.
    expect(Media::query()->findOrFail($version->id)->file_name)->toBe($renamed->file_name)
        ->and(Media::query()->findOrFail($version->id)->name)->toBe($renamed->name);
});

it('moves the selection onto the row it restored', function (): void {
    $first = feVeUpload();
    $second = feVeUpload();

    $component = feVeComponent()
        ->call('showInfo', 'file', $second->id)
        ->call('restoreVersion', $first->id);

    // The restored row is a *new* media id. Leaving the selection on the one
    // just set aside would leave the inspector describing a row that has left
    // the listing, and refreshInfo() would answer with a 403 rather than a panel.
    $component->assertOk()
        ->assertSet('selectedFiles', [(int) $first->id])
        ->assertSet('infoItem.id', (int) $first->id);
});

it('numbers a restored version as the newest', function (): void {
    $first = feVeUpload();
    feVeUpload();

    feVeComponent()->call('restoreVersion', $first->id);

    $line = FileVersion::query()->where('media_id', $first->id)->firstOrFail();

    // Stored rather than derived from the order of the ids, because restoring
    // moves a version back to the head and its number has to say so.
    expect($line->sequence)->toBe(3)
        ->and($line->replaced_at)->toBeNull();
});

it('refuses to restore a version of a file that is in the trash', function (): void {
    $first = feVeUpload();
    $live = feVeUpload();

    feVeComponent()->call('deleteSelected', [], [$live->id]);

    // Restoring the file is the trash's job. Doing it here would put back
    // something the user deleted, through a panel that is not about deleting.
    feVeComponent()->call('restoreVersion', $first->id)->assertOk();

    expect(Media::query()->findOrFail($first->id)->collection_name)->toBe(Versions::collection());
});

it('refuses to restore a live row as if it were a version', function (): void {
    $live = feVeUpload();

    feVeComponent()->call('restoreVersion', $live->id)->assertForbidden();
});

it('refuses a version outside the root', function (): void {
    $outside = FolderModel::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'parent_id' => null]);

    $stray = Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => $outside->id,
        'collection_name' => Versions::collection(),
        'name' => 'stray.pdf',
        'file_name' => 'stray.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 8,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    // Containment still applies with the collection named explicitly: naming a
    // collection must not stop proving that the row is a folder's, and that the
    // folder is under this root.
    feVeComponent()->call('restoreVersion', $stray->id)->assertForbidden();
});

it('refuses to restore without the ability', function (): void {
    $first = feVeUpload();
    feVeUpload();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return array_merge(array_fill_keys(Abilities::ORIGINAL, true), ['upload' => false]);
        }
    });
    app()->forgetScopedInstances();

    // Inherited from `upload`, which this authorizer denies and which never
    // heard of `restoreVersion` at all: making a version current is the same act
    // as uploading over the file.
    feVeComponent()->call('restoreVersion', $first->id)->assertForbidden();
});

/* ------------------------------------------------------------------- inspector */

it('lists the history of the file the inspector describes', function (): void {
    feVeUpload();
    $live = feVeUpload();

    $component = feVeComponent()->call('showInfo', 'file', $live->id);

    expect($component->get('versions'))->toHaveCount(1);
    expect($component->assertOk()->html())->toContain('fe-versions__list');
});

it('shows nothing for a file nobody has replaced', function (): void {
    $live = feVeUpload();

    $component = feVeComponent()->call('showInfo', 'file', $live->id);

    // An empty panel saying so on every file in the library would be noise.
    expect($component->get('versions'))->toBe([]);
    expect($component->assertOk()->html())->not->toContain('fe-versions__list');
});

it('drops the history when the panel moves to a folder', function (): void {
    feVeUpload();
    $live = feVeUpload();

    $folderId = (int) FolderModel::query()->create([
        'name' => 'Docs', 'slug' => 'docs', 'parent_id' => feVeRootId(),
    ])->id;

    $component = feVeComponent()->call('showInfo', 'file', $live->id);
    expect($component->get('versions'))->toHaveCount(1);

    // A folder is not replaced, it is renamed. Loaded through one call beside the
    // annotations rather than at each of showInfo()'s branches, which is what
    // stops one branch keeping the last panel's history.
    $component->call('showInfo', 'folder', $folderId);

    expect($component->get('versions'))->toBe([]);
});

it('tells nobody about a history they may not read', function (): void {
    feVeUpload();
    $live = feVeUpload();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return array_merge(array_fill_keys(Abilities::ORIGINAL, true), ['getInfo' => false]);
        }
    });
    app()->forgetScopedInstances();

    // showInfo() itself is gated on getInfo, so the panel never opens — the point
    // is that `viewVersions` follows it rather than defaulting to allowed.
    expect(app(Abilities::class)->allows('library', feVeRootId(), 'viewVersions'))->toBeFalse();

    feVeComponent()->call('showInfo', 'file', $live->id)->assertForbidden();
});

it('offers no restore button to someone who cannot upload', function (): void {
    feVeUpload();
    $live = feVeUpload();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return array_merge(array_fill_keys(Abilities::ORIGINAL, true), ['upload' => false]);
        }
    });
    app()->forgetScopedInstances();

    $html = feVeComponent()->call('showInfo', 'file', $live->id)->assertOk()->html();

    expect($html)->toContain('fe-versions__list')
        ->and($html)->not->toContain('fe-versions__restore');
});

it('does not let the client author a file history', function (): void {
    feVeUpload();
    $live = feVeUpload();

    $component = feVeComponent()->call('showInfo', 'file', $live->id);

    // Nothing on the server reads it, but the panel it draws claims what a file's
    // past was, and that is not a claim the browser gets to make.
    expect(fn () => $component->set('versions', [['id' => 1, 'sequence' => 9]]))
        ->toThrow('Cannot update locked property: [versions]');
});

/* -------------------------------------------------------------------- disabled */

it('destroys the old file when versioning is off', function (): void {
    config(['filament-file-explorer.versions.enabled' => false]);

    $first = feVeUpload();
    feVeUpload();

    expect(Media::query()->find($first->id))->toBeNull()
        ->and(feVeKept())->toBe(0)
        ->and(FileVersion::query()->count())->toBe(0);
});

it('reads no history when versioning is off', function (): void {
    $live = feVeUpload();
    feVeUpload();

    config(['filament-file-explorer.versions.enabled' => false]);

    expect(app(Versions::class)->history((int) $live->id))->toBe([]);
});

it('leaves the other conflict policies alone', function (): void {
    config(['filament-file-explorer.upload.on_conflict' => 'rename']);

    feVeUpload();
    feVeComponent()->set('files', [feVeFile()]);

    // Versioning is what `replace` does instead of destroying. It is not a
    // second answer to a name clash.
    expect(feVeLive())->toBe(2)
        ->and(feVeKept())->toBe(0)
        ->and(FileVersion::query()->count())->toBe(0);
});
