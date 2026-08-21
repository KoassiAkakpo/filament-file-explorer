<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Tests\Fixtures\CustomFolder;
use Koassi\FilamentFileExplorer\Tests\Fixtures\Project;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');

    config(['filament-file-explorer.folders.model' => CustomFolder::class]);
});

function feFmRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feFmComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feFmRootId(),
    ]);
}

it('resolves the configured class', function (): void {
    expect(FolderModel::class())->toBe(CustomFolder::class)
        ->and(FolderModel::new())->toBeInstanceOf(CustomFolder::class);
});

it('falls back to the package model when nothing is configured', function (): void {
    config(['filament-file-explorer.folders.model' => null]);

    expect(FolderModel::class())->toBe(Folder::class);
});

it('refuses a class that does not extend the package model', function (): void {
    config(['filament-file-explorer.folders.model' => Model::class]);

    FolderModel::class();
})->throws(InvalidArgumentException::class, 'does not extend');

it('builds the root through the configured class', function (): void {
    $root = app(FileExplorerManager::class)->ensureRoot('Library', 'library');

    expect($root)->toBeInstanceOf(CustomFolder::class)
        ->and($root->isTheHostAppsModel())->toBeTrue();
});

it('hands the explorer the configured class', function (): void {
    FolderModel::query()->create(['name' => 'Reports', 'slug' => 'reports', 'parent_id' => feFmRootId()]);

    $component = feFmComponent();

    expect($component->instance()->currentFolder)->toBeInstanceOf(CustomFolder::class)
        ->and($component->instance()->listing()['folders']->first())->toBeInstanceOf(CustomFolder::class);
});

it('keeps the relations on the configured class', function (): void {
    $parent = FolderModel::query()->create(['name' => 'Parent', 'slug' => 'parent', 'parent_id' => feFmRootId()]);
    $child = FolderModel::query()->create(['name' => 'Child', 'slug' => 'child', 'parent_id' => $parent->id]);

    // self::class in the relations would have pinned these to the base model,
    // so a host app would get its own class back from a query and the package's
    // from a relation.
    expect($parent->children->first())->toBeInstanceOf(CustomFolder::class)
        ->and($child->parent)->toBeInstanceOf(CustomFolder::class);
});

it('keeps storing the package class in model_type', function (): void {
    feFmComponent()->set('files', [UploadedFile::fake()->create('report.pdf', 12, 'application/pdf')]);

    $media = Media::query()->where('file_name', 'report.pdf')->firstOrFail();

    // The whole reason getMorphClass() answers for self and not static: a
    // library uploaded before the swap must still pass the containment guard,
    // which checks model_type. Following the subclass would orphan all of it.
    expect($media->model_type)->toBe(Folder::class)
        ->and(FolderModel::morphClass())->toBe(Folder::class);
});

it('serves a file uploaded under the swapped model', function (): void {
    feFmComponent()->set('files', [UploadedFile::fake()->create('report.pdf', 12, 'application/pdf')]);

    $media = Media::query()->where('file_name', 'report.pdf')->firstOrFail();

    // Reached through the listing and the containment check, both of which
    // compare model_type.
    expect(feFmComponent()->instance()->listing()['files']->pluck('id')->all())
        ->toContain($media->id);
});

it('trashes and restores through the configured class', function (): void {
    $folder = FolderModel::query()->create(['name' => 'Doomed', 'slug' => 'doomed', 'parent_id' => feFmRootId()]);

    feFmComponent()->call('deleteSelected', [$folder->id], []);

    expect(FolderModel::query()->find($folder->id))->toBeNull()
        ->and(FolderModel::query()->withTrashed()->find($folder->id))->toBeInstanceOf(CustomFolder::class);

    feFmComponent()->call('restoreItem', 'folder', $folder->id);

    expect(FolderModel::query()->find($folder->id))->not->toBeNull();
});

it('points the model trait relation at the configured class', function (): void {
    $project = Project::query()->create(['name' => 'Apollo']);

    expect($project->folder)->toBeInstanceOf(CustomFolder::class);
});

it('leaves the trash counting the same rows', function (): void {
    $folder = FolderModel::query()->create(['name' => 'Gone', 'slug' => 'gone', 'parent_id' => feFmRootId()]);

    feFmComponent()->call('deleteSelected', [$folder->id], []);

    expect(app(Trash::class)->count(feFmRootId()))->toBe(1);
});

it('serves the media route with the model swapped', function (): void {
    $this->actingAs(new User(['id' => 1]));

    feFmComponent()->set('files', [UploadedFile::fake()->create('report.pdf', 12, 'application/pdf')]);

    $media = Media::query()->where('file_name', 'report.pdf')->firstOrFail();

    // The route's containment guard requires model_type to be the Folder morph
    // class: it is the check that would have broken silently had the morph class
    // followed the subclass.
    $this->get(route('filament-file-explorer.media.show', [
        'scopeKey' => 'library',
        'media' => $media->id,
    ]))->assertOk();
});

it('builds the folder model through the resolver everywhere', function (): void {
    $offenders = [];

    // Static construction pins the class, which is the whole thing the resolver
    // exists to undo — and it creeps back the moment someone adds a query
    // without thinking about it.
    $forbidden = [
        '/(?<![\\w\\\\])new\\s+Folder(?![\\w])/' => 'new Folder',
        '/(?<![\\w\\\\])Folder::(query|withTrashed|onlyTrashed|with)\\s*\\(/' => 'Folder:: static query',
    ];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if ($file->getExtension() !== 'php' || $file->getFilename() === 'FolderModel.php') {
            continue;
        }

        // Comments talk about the old forms on purpose, so they are stripped
        // rather than matched.
        $code = implode('', array_map(
            fn (array|string $token): string => is_string($token)
                ? $token
                : (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]),
            token_get_all((string) file_get_contents($file->getPathname())),
        ));

        foreach ($forbidden as $pattern => $label) {
            if (preg_match($pattern, $code) === 1) {
                $offenders[] = $file->getFilename().' → '.$label;
            }
        }
    }

    expect($offenders)->toBe([]);
});
