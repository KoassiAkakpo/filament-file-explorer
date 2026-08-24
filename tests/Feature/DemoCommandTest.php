<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Support\Annotations;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\FileKinds;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\Uploader;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * @param  array<string, mixed>  $options
 */
function feDemo(array $options = []): void
{
    test()->artisan('file-explorer:demo', array_merge([
        '--files' => 24,
        '--folders' => 6,
        '--seed' => 4242,
        '--force' => true,
    ], $options))->assertSuccessful()->run();
}

function feDemoLive(): Builder
{
    return Media::query()
        ->where('model_type', FolderModel::morphClass())
        ->where('collection_name', UploadRules::collection());
}

it('fills the root the panel would open', function (): void {
    feDemo();

    // Not a root of its own invention: the standalone resolver's, which is the
    // library the explorer page actually shows. A demo in a folder nobody
    // browses demonstrates nothing.
    $resolved = app(FileExplorerRootResolver::class)->rootFolderId();

    expect(FolderModel::query()->where('parent_id', $resolved)->count())->toBeGreaterThan(0)
        ->and(feDemoLive()->count())->toBeGreaterThan(0);
});

it('writes real bytes, never a size over an empty file', function (): void {
    feDemo();

    // The trap the package's own tests document: UploadedFile::fake()->create()
    // reports a size and writes nothing, so the quota bar and the storage
    // widget both sit at zero. A demo whose whole job is to show them full
    // cannot be built that way.
    expect(feDemoLive()->where('size', '<=', 0)->count())->toBe(0);

    $root = app(FileExplorerRootResolver::class)->rootFolderId();

    expect(app(Quota::class)->usedBytes($root))->toBeGreaterThan(20000);

    feDemoLive()->get()->each(function (Media $media): void {
        expect(Storage::disk($media->disk)->exists($media->id.'/'.$media->file_name))->toBeTrue();
    });
});

it('produces a file of every kind the filter offers', function (): void {
    feDemo(['--files' => 90]);

    // The kind filter matches on mime_type, sniffed from the bytes — so a kind
    // the demo cannot honestly produce would be a menu entry that finds
    // nothing, and read as a broken filter rather than an honest gap.
    foreach (FileKinds::all() as $kind) {
        expect(FileKinds::apply(feDemoLive(), $kind)->count())
            ->toBeGreaterThan(0, 'no demo file matched the '.$kind.' filter');
    }
});

it('produces files the explorer would have accepted itself', function (): void {
    feDemo();

    $allowed = UploadRules::acceptedMimeTypes();

    // A demo library holding something the upload rules refuse would show a
    // state the explorer cannot reach — and the first thing anyone does with a
    // demo is try to re-upload one of its files.
    feDemoLive()->get()->each(function (Media $media) use ($allowed): void {
        expect($allowed)->toContain((string) $media->mime_type);
        expect(UploadRules::acceptedExtensions())
            ->toContain(strtolower(pathinfo((string) $media->file_name, PATHINFO_EXTENSION)));
    });
});

it('gives its images real thumbnails', function (): void {
    feDemo(['--files' => 40]);

    $images = feDemoLive()->where('mime_type', 'like', 'image/%')->get();

    expect($images)->not->toBeEmpty();

    // Real images, not files merely named .png: the conversion is the thing
    // worth demonstrating, and GD cannot convert a lie.
    $images->each(function (Media $media): void {
        expect($media->hasGeneratedConversion('thumbnail'))->toBeTrue();
    });
})->skip(! extension_loaded('gd'), 'needs GD');

it('tags and describes some of the library, never all of it', function (): void {
    feDemo(['--files' => 40]);

    $tags = app(Annotations::class)->available('library');
    $files = feDemoLive()->pluck('id')->all();
    $tagged = app(Annotations::class)->tagsFor(Annotations::FILE, $files);
    $carrying = count(array_filter($tagged, fn (array $list): bool => $list !== []));

    // A filter that matches everything shows nothing off, and neither does an
    // inspector where every item is described.
    expect($tags)->not->toBeEmpty()
        ->and($carrying)->toBeGreaterThan(0)
        ->and($carrying)->toBeLessThan(count($files));

    // Folders too — the listing narrows both, and hiding the folders that carry
    // a tag would hide exactly what was tagged.
    $folders = FolderModel::query()->pluck('id')->all();
    $taggedFolders = app(Annotations::class)->tagsFor(Annotations::FOLDER, $folders);

    expect(array_filter($taggedFolders, fn (array $list): bool => $list !== []))->not->toBeEmpty();
});

it('leaves something in the trash to look at', function (): void {
    feDemo();

    $root = app(FileExplorerRootResolver::class)->rootFolderId();

    // Through Trash itself, so the two halves are the real ones: a trashed
    // folder is soft deleted, a trashed file changes collection.
    expect(app(Trash::class)->count($root))->toBeGreaterThan(0)
        ->and(FolderModel::query()->onlyTrashed()->count())->toBeGreaterThan(0)
        ->and(Media::query()->where('collection_name', Trash::collection())->count())->toBeGreaterThan(0);
});

it('gives the same library back for the same seed', function (): void {
    feDemo();

    $first = [
        FolderModel::query()->withTrashed()->orderBy('id')->pluck('name')->all(),
        Media::query()->orderBy('id')->pluck('name')->all(),
        Media::query()->orderBy('id')->pluck('size')->all(),
    ];

    feDemo(['--fresh' => true]);

    $second = [
        FolderModel::query()->withTrashed()->orderBy('id')->pluck('name')->all(),
        Media::query()->orderBy('id')->pluck('name')->all(),
        Media::query()->orderBy('id')->pluck('size')->all(),
    ];

    // Which is why nothing in the tree is named with Str::random: a screenshot
    // or a bug report is only reproducible if the seed is the whole story.
    expect($second[0])->toBe($first[0])
        ->and($second[1])->toBe($first[1])
        ->and($second[2])->toBe($first[2]);
});

it('empties the root on --fresh without reaching outside it', function (): void {
    $other = app(FileExplorerManager::class)->createRoot('Other library', 'other-library');
    $kept = app(FileExplorerManager::class)->createChild($other, 'Keep me');

    feDemo();

    $before = feDemoLive()->count();

    feDemo(['--fresh' => true]);

    // Emptied, not doubled — and the other root untouched: one scope's demo is
    // not a licence to clear the database.
    expect(feDemoLive()->count())->toBeLessThanOrEqual($before + 2)
        ->and(FolderModel::query()->withTrashed()->find($kept->id))->not->toBeNull()
        ->and(FolderModel::query()->withTrashed()->find($other->id))->not->toBeNull();
});

it('purges rather than trashes what --fresh removes', function (): void {
    feDemo();
    feDemo(['--fresh' => true]);

    // The folder model soft deletes, so a plain delete would leave every
    // previous run piling up in a trash nobody asked to fill. What the trash
    // holds is what this run put there: the one folder the demo trashes on
    // purpose, and nothing the last run left behind.
    expect(FolderModel::query()->onlyTrashed()->count())->toBe(1);
});

it('refuses --root without the scope its tags would belong to', function (): void {
    $folder = app(FileExplorerManager::class)->createRoot('Unscoped', 'unscoped');

    $this->artisan('file-explorer:demo', ['--root' => $folder->id, '--files' => 2, '--force' => true])
        ->assertFailed();

    // A folder cannot name its scope — a record-scoped page builds the key from
    // the model and the record — so guessing would tag into a vocabulary the
    // explorer never offers.
    expect(FolderModel::query()->where('parent_id', $folder->id)->count())->toBe(0);
});

it('fills the folder --root names', function (): void {
    $folder = app(FileExplorerManager::class)->createRoot('Project 4', 'project-4');

    feDemo(['--root' => $folder->id, '--scope' => 'project.4', '--files' => 6, '--folders' => 3]);

    expect(FolderModel::query()->where('parent_id', $folder->id)->count())->toBeGreaterThan(0)
        ->and(app(Annotations::class)->available('project.4'))->not->toBeEmpty()
        ->and(app(Annotations::class)->available('library'))->toBeEmpty();
});

it('refuses a --root that does not exist', function (): void {
    $this->artisan('file-explorer:demo', ['--root' => 9999, '--scope' => 'library', '--force' => true])
        ->assertFailed();
});

it('records the uploader when told who it is', function (): void {
    config()->set('auth.providers.users.model', User::class);
    $user = User::query()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    feDemo(['--files' => 4, '--user' => $user->id]);

    // What fills the inspector's "Added by". Recorded on upload since the first
    // version, and a demo with it empty leaves a visible hole in the panel.
    $media = feDemoLive()->first();

    expect($media->getCustomProperty('uploaded_by_id'))->toBe($user->id)
        ->and(app(Uploader::class)->label($media))->toBe('Ada Lovelace');
});

it('fails on a --user nobody is', function (): void {
    config()->set('auth.providers.users.model', User::class);

    $this->artisan('file-explorer:demo', ['--user' => 77, '--force' => true])->assertFailed();

    expect(FolderModel::query()->count())->toBe(0);
});

it('stops at the quota instead of filling past it', function (): void {
    config()->set('filament-file-explorer.quota.bytes', 60000);

    feDemo(['--files' => 60]);

    $root = app(FileExplorerRootResolver::class)->rootFolderId();

    // The same gate an upload passes. A demo that put the scope over its cap
    // would leave the first thing anyone tries — uploading — refused.
    expect(app(Quota::class)->usedBytes($root))->toBeLessThanOrEqual(60000);
});

it('never digs deeper than the configured maximum', function (): void {
    config()->set('filament-file-explorer.folders.max_depth', 2);

    feDemo(['--depth' => 6, '--folders' => 20]);

    $root = app(FileExplorerRootResolver::class)->rootFolderId();
    $depthOf = function (int $id) use ($root): int {
        $depth = 0;

        while ($id !== $root) {
            $id = (int) FolderModel::query()->withTrashed()->find($id)?->parent_id;
            $depth++;

            if ($depth > 10) {
                break;
            }
        }

        return $depth;
    };

    // Deeper than the explorer allows means folders the move dialog then
    // refuses to accept — a demo of a state the package forbids.
    FolderModel::query()->withTrashed()->where('id', '!=', $root)->pluck('id')
        ->each(fn ($id) => expect($depthOf((int) $id))->toBeLessThanOrEqual(2));
});

it('creates nothing when asked for nothing', function (): void {
    feDemo(['--files' => 0, '--folders' => 0]);

    expect(feDemoLive()->count())->toBe(0)
        ->and(FolderModel::query()->count())->toBe(1);
});

it('leaves no working files behind', function (): void {
    feDemo(['--files' => 6]);

    // The generated file is handed to Media Library, which moves it — but the
    // ones a quota refused, and the directory itself, are this command's to
    // clean up.
    expect(is_dir(storage_path('app/file-explorer-demo')))->toBeFalse();
});
