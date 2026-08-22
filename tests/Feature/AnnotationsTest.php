<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Events\FileAnnotated;
use Koassi\FilamentFileExplorer\Events\FolderAnnotated;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Tag;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\Annotations;
use Koassi\FilamentFileExplorer\Support\FolderListing;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feAnRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feAn(): Annotations
{
    return app(Annotations::class);
}

function feAnComponent(string $scopeKey = 'library'): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => $scopeKey,
        'rootFolderId' => feAnRootId(),
    ]);
}

function feAnFolder(string $name, ?int $parentId = null): int
{
    return (int) FolderModel::query()->create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        'parent_id' => $parentId ?? feAnRootId(),
    ])->id;
}

function feAnMedia(string $name, ?int $folderId = null, string $collection = ''): Media
{
    return Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => $folderId ?? feAnRootId(),
        'collection_name' => $collection !== '' ? $collection : UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 8,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

function feAnListing(int $tagId = 0, string $search = ''): array
{
    return (new FolderListing(
        feAnRootId(),
        feAnRootId(),
        $search,
        'name',
        'asc',
        null,
        $tagId > 0 ? $tagId : null,
    ))->window(50);
}

/* ------------------------------------------------------------------ storage */

it('keeps a description for a file and for a folder', function (): void {
    $media = feAnMedia('report.pdf');
    $folderId = feAnFolder('Docs');

    feAn()->setDescription(Annotations::FILE, (int) $media->id, '  Quarterly figures  ');
    feAn()->setDescription(Annotations::FOLDER, $folderId, 'Everything legal');

    // Trimmed on the way in, and the two kinds never read each other's rows even
    // when the ids collide.
    expect(feAn()->description(Annotations::FILE, (int) $media->id))->toBe('Quarterly figures')
        ->and(feAn()->description(Annotations::FOLDER, $folderId))->toBe('Everything legal');
});

it('drops the row when a description is emptied', function (): void {
    $media = feAnMedia('report.pdf');

    feAn()->setDescription(Annotations::FILE, (int) $media->id, 'something');
    feAn()->setDescription(Annotations::FILE, (int) $media->id, '   ');

    expect(feAn()->description(Annotations::FILE, (int) $media->id))->toBe('')
        ->and(DB::table(Annotations::descriptionsTable())->count())->toBe(0);
});

it('creates the scope word once, however many items carry it', function (): void {
    $a = feAnMedia('a.pdf');
    $b = feAnMedia('b.pdf');

    feAn()->attach('library', Annotations::FILE, (int) $a->id, 'Urgent');

    // The same word with different capitalisation is the same tag: uniqueness is
    // decided on the slug, which every driver compares the same way.
    feAn()->attach('library', Annotations::FILE, (int) $b->id, 'urgent');

    expect(Tag::query()->count())->toBe(1)
        ->and(feAn()->tags(Annotations::FILE, (int) $b->id))->toHaveCount(1);
});

it('attaching the same tag twice changes nothing', function (): void {
    $media = feAnMedia('a.pdf');

    feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');
    $tags = feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');

    expect($tags)->toHaveCount(1)
        ->and(DB::table(Annotations::tagItemsTable())->count())->toBe(1);
});

it('drops a tag nothing carries any more', function (): void {
    $media = feAnMedia('a.pdf');

    $tags = feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');
    feAn()->detach(Annotations::FILE, (int) $media->id, $tags[0]['id']);

    // Otherwise the filter menu keeps offering a word that matches nothing, and
    // the package grows a tag management screen to go and tidy it.
    expect(Tag::query()->count())->toBe(0);
});

it('keeps the vocabularies of two scopes apart', function (): void {
    $media = feAnMedia('a.pdf');

    feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');
    feAn()->attach('other', Annotations::FILE, (int) $media->id, 'Urgent');

    expect(Tag::query()->count())->toBe(2)
        ->and(feAn()->available('library'))->toHaveCount(1)
        ->and(feAn()->available('other'))->toHaveCount(1);
});

it('refuses a name that slugs to nothing', function (): void {
    $media = feAnMedia('a.pdf');

    // No stable identity to deduplicate on, so it is not a tag.
    expect(feAn()->attach('library', Annotations::FILE, (int) $media->id, '...'))->toBe([])
        ->and(Tag::query()->count())->toBe(0);
});

it('falls back to no colour for one that is not in the palette', function (): void {
    $media = feAnMedia('a.pdf');

    $tags = feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent', 'chartreuse');

    expect($tags[0]['color'])->toBeNull()
        ->and(Annotations::colour('blue'))->toBe('blue');
});

/* ------------------------------------------------------------------ listing */

it('narrows the listing to one tag, folders included', function (): void {
    $taggedFolder = feAnFolder('Tagged');
    feAnFolder('Plain');
    $taggedFile = feAnMedia('tagged.pdf');
    feAnMedia('plain.pdf');

    $tags = feAn()->attach('library', Annotations::FOLDER, $taggedFolder, 'Urgent');
    feAn()->attach('library', Annotations::FILE, (int) $taggedFile->id, 'Urgent');

    $listing = feAnListing($tags[0]['id']);

    // Both kinds, unlike the kind filter: a folder can be tagged, so hiding
    // folders here would hide exactly what the user tagged.
    expect($listing['folders']->pluck('name')->all())->toBe(['Tagged'])
        ->and($listing['files']->pluck('name')->all())->toBe(['tagged.pdf'])
        ->and($listing['total'])->toBe(2);
});

it('finds an item by a word in its description', function (): void {
    $media = feAnMedia('untitled-1.pdf');
    feAnMedia('untitled-2.pdf');

    feAn()->setDescription(Annotations::FILE, (int) $media->id, 'The signed lease agreement');

    // A note nobody can find again is worth nothing, which is also why neither
    // descriptions nor tags live in a JSON column.
    expect(feAnListing(search: 'lease')['files']->pluck('name')->all())->toBe(['untitled-1.pdf']);
});

it('finds an item by one of its tags', function (): void {
    $folderId = feAnFolder('Q1');
    feAnFolder('Q2');

    feAn()->attach('library', Annotations::FOLDER, $folderId, 'Urgent');

    expect(feAnListing(search: 'urgent')['folders']->pluck('name')->all())->toBe(['Q1']);
});

it('still matches the name when nothing is annotated', function (): void {
    feAnMedia('lease.pdf');
    feAnMedia('invoice.pdf');

    expect(feAnListing(search: 'lease')['files']->pluck('name')->all())->toBe(['lease.pdf']);
});

it('treats a wildcard typed in the search box as a literal', function (): void {
    $plain = feAnMedia('a.pdf');
    $media = feAnMedia('b.pdf');

    feAn()->setDescription(Annotations::FILE, (int) $plain->id, 'nothing special');
    feAn()->setDescription(Annotations::FILE, (int) $media->id, 'literally 100% done');

    // The escape rule lives in FolderListing and Annotations borrows it: two
    // copies of it would drift, and the annotation search would quietly become
    // the one place "%" still meant "anything". Unescaped, the second search
    // below matches every described row instead of the one holding a per cent
    // sign.
    expect(feAnListing(search: '100%')['files']->pluck('name')->all())->toBe(['b.pdf'])
        ->and(feAnListing(search: '%')['files']->pluck('name')->all())->toBe(['b.pdf']);
});

it('counts the filtered set, not the folder', function (): void {
    $tagged = feAnMedia('tagged.pdf');
    feAnMedia('plain-a.pdf');
    feAnMedia('plain-b.pdf');

    $tags = feAn()->attach('library', Annotations::FILE, (int) $tagged->id, 'Urgent');

    $listing = feAnListing($tags[0]['id']);

    // Applied before the window and before the counts, like the kind filter, so
    // "1 of 1" describes what the filter left.
    expect($listing['total'])->toBe(1)
        ->and($listing['shown'])->toBe(1)
        ->and($listing['hasMore'])->toBeFalse();
});

/* ---------------------------------------------------------------- component */

it('seeds the inspector with the annotation and clears it on close', function (): void {
    $media = feAnMedia('report.pdf');

    feAn()->setDescription(Annotations::FILE, (int) $media->id, 'Quarterly figures');
    feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');

    $component = feAnComponent()->call('showInfo', 'file', $media->id);

    expect($component->get('description'))->toBe('Quarterly figures')
        ->and($component->get('tags'))->toHaveCount(1);

    // The panel following the selection must not leave one item's note in the
    // textarea of the next.
    $component->call('closeInfo');

    expect($component->get('description'))->toBe('')
        ->and($component->get('tags'))->toBe([]);
});

it('writes a description through the inspector', function (): void {
    $media = feAnMedia('report.pdf');

    feAnComponent()
        ->call('showInfo', 'file', $media->id)
        ->set('description', 'Quarterly figures');

    // set() goes through updatedDescription, which is what saves: an action on
    // the same blur event would race the property update.
    expect(feAn()->description(Annotations::FILE, (int) $media->id))->toBe('Quarterly figures');
});

it('adds and removes a tag through the inspector', function (): void {
    $media = feAnMedia('report.pdf');

    $component = feAnComponent()
        ->call('showInfo', 'file', $media->id)
        ->set('tagInput', 'Urgent')
        ->call('addTag');

    expect($component->get('tags'))->toHaveCount(1)
        ->and($component->get('tagInput'))->toBe('');

    $component->call('removeTag', $component->get('tags')[0]['id']);

    expect($component->get('tags'))->toBe([]);
});

it('toggles the tag filter and refuses one from another scope', function (): void {
    $media = feAnMedia('report.pdf');
    $tags = feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');
    $foreign = feAn()->attach('elsewhere', Annotations::FILE, (int) $media->id, 'Secret');

    $component = feAnComponent()->call('setTagFilter', $tags[0]['id']);
    expect($component->get('tagId'))->toBe($tags[0]['id']);

    // Handed the active one again, it clears — the menu entry is a toggle.
    $component->call('setTagFilter', $tags[0]['id']);
    expect($component->get('tagId'))->toBeNull();

    // A tag of another scope narrows nothing rather than reaching across.
    $component->call('setTagFilter', $foreign[0]['id']);
    expect($component->get('tagId'))->toBeNull();
});

it('clears a stored filter whose tag has since been dropped', function (): void {
    $media = feAnMedia('report.pdf');
    $tags = feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');

    $component = feAnComponent()->call('setTagFilter', $tags[0]['id']);

    feAn()->detach(Annotations::FILE, (int) $media->id, $tags[0]['id']);

    // Re-validated on every listing, so a filter left pointing at nothing does
    // not empty the folder with no explanation.
    $component->call('resetListing')->assertOk();

    expect($component->get('tagId'))->toBeNull();
});

/* ------------------------------------------------------------- authorization */

it('declares annotate as following rename', function (): void {
    expect(Abilities::DERIVED)->toHaveKey('annotate')
        ->and(Abilities::DERIVED['annotate'])->toBe('rename');
});

it('refuses to annotate without the ability', function (): void {
    $media = feAnMedia('report.pdf');

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return array_merge(array_fill_keys(Abilities::ORIGINAL, true), ['rename' => false]);
        }
    });
    app()->forgetScopedInstances();

    $component = feAnComponent()->call('showInfo', 'file', $media->id);

    // Inherited from rename, which this authorizer denies and never heard of
    // `annotate` at all.
    expect($component->instance()->canAnnotate())->toBeFalse();

    $component->set('tagInput', 'Urgent')->call('addTag')->assertForbidden();
});

it('refuses to annotate a folder outside the root', function (): void {
    $outside = FolderModel::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'parent_id' => null]);

    $component = feAnComponent();
    $component->set('infoItem', ['type' => 'folder', 'id' => (int) $outside->id, 'scope' => 'selection']);

    // The ids come from the panel, so annotating goes through containment like
    // every other action.
    $component->set('tagInput', 'Urgent')->call('addTag')->assertForbidden();
});

/* ------------------------------------------------------------------ lifecycle */

it('forgets the annotations of a purged file but keeps a trashed one', function (): void {
    $trashed = feAnMedia('trashed.pdf', collection: Trash::collection());
    $purged = feAnMedia('purged.pdf');

    feAn()->setDescription(Annotations::FILE, (int) $trashed->id, 'still mine');
    feAn()->setDescription(Annotations::FILE, (int) $purged->id, 'not for long');

    $purged->delete();

    // A file in the trash is coming back, and it must come back annotated.
    expect(feAn()->description(Annotations::FILE, (int) $trashed->id))->toBe('still mine')
        ->and(feAn()->description(Annotations::FILE, (int) $purged->id))->toBe('');
});

it('leaves the annotations of another model alone', function (): void {
    $ours = feAnMedia('ours.pdf');

    feAn()->setDescription(Annotations::FILE, (int) $ours->id, 'ours');

    // Same id, another model: an annotation is keyed by (kind, id), so deleting
    // this row must not take our file's description with it.
    $foreign = Media::query()->create([
        'model_type' => 'App\\Models\\Invoice',
        'model_id' => 1,
        'collection_name' => UploadRules::collection(),
        'name' => 'foreign.pdf',
        'file_name' => 'foreign.pdf',
        'disk' => 'public',
        'size' => 1,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    DB::table('media')->where('id', $foreign->id)->update(['id' => $ours->id + 1000]);
    $renumbered = Media::query()->findOrFail($ours->id + 1000);

    feAn()->setDescription(Annotations::FILE, (int) $renumbered->id, 'theirs');
    $renumbered->delete();

    expect(feAn()->description(Annotations::FILE, (int) $ours->id))->toBe('ours');
});

it('forgets the annotations of a folder only when it is really gone', function (): void {
    $folderId = feAnFolder('Docs');

    feAn()->setDescription(Annotations::FOLDER, $folderId, 'legal');
    feAn()->attach('library', Annotations::FOLDER, $folderId, 'Urgent');

    $folder = FolderModel::query()->findOrFail($folderId);
    $folder->delete();

    // Soft-deleted is the trash, and the trash gives things back.
    expect(feAn()->description(Annotations::FOLDER, $folderId))->toBe('legal');

    $folder->forceDelete();

    expect(feAn()->description(Annotations::FOLDER, $folderId))->toBe('')
        ->and(Tag::query()->count())->toBe(0);
});

/* --------------------------------------------------------------------- events */

it('announces an annotation with both sides of the change', function (): void {
    Event::fake([FileAnnotated::class, FolderAnnotated::class]);

    $media = feAnMedia('report.pdf');
    $folderId = feAnFolder('Docs');

    feAnComponent()
        ->call('showInfo', 'file', $media->id)
        ->set('description', 'Quarterly figures');

    feAnComponent()
        ->call('showInfo', 'folder', $folderId)
        ->set('tagInput', 'Urgent')
        ->call('addTag');

    Event::assertDispatched(FileAnnotated::class, function (FileAnnotated $event): bool {
        return $event->description === 'Quarterly figures'
            && $event->previousDescription === ''
            && $event->scopeKey === 'library';
    });

    Event::assertDispatched(FolderAnnotated::class, function (FolderAnnotated $event): bool {
        return $event->tags === ['Urgent'] && $event->previousTags === [];
    });
});

it('says nothing when a save changes nothing', function (): void {
    Event::fake([FileAnnotated::class]);

    $media = feAnMedia('report.pdf');
    feAn()->setDescription(Annotations::FILE, (int) $media->id, 'Quarterly figures');

    feAnComponent()
        ->call('showInfo', 'file', $media->id)
        ->set('description', 'Quarterly figures');

    Event::assertNotDispatched(FileAnnotated::class);
});

/* ----------------------------------------------------------------- batching */

it('reads the tags of a whole window in two queries', function (): void {
    foreach (range(1, 6) as $n) {
        $media = feAnMedia('file-'.$n.'.pdf');
        feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent');
        feAn()->attach('library', Annotations::FOLDER, feAnFolder('Folder '.$n), 'Urgent');
    }

    app()->forgetScopedInstances();

    $component = feAnComponent();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $component->instance()->tagIndex();

    // One per kind. Asking per row is how a folder of a hundred files becomes a
    // hundred queries — the listing itself costs the rest.
    expect($queries)->toBeLessThanOrEqual(6);
});

it('does nothing at all when the feature is off', function (): void {
    config()->set('filament-file-explorer.annotations.enabled', false);

    $media = feAnMedia('report.pdf');

    feAn()->setDescription(Annotations::FILE, (int) $media->id, 'ignored');

    expect(feAn()->description(Annotations::FILE, (int) $media->id))->toBe('')
        ->and(feAn()->attach('library', Annotations::FILE, (int) $media->id, 'Urgent'))->toBe([])
        ->and(feAn()->available('library'))->toBe([])
        ->and(feAnComponent()->instance()->canAnnotate())->toBeFalse();
});
