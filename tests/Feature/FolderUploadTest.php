<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

function feFuRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feFuComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feFuRoot()->id,
    ]);
}

/**
 * Mirrors what the browser sends for a folder upload: the files, plus one
 * relative path per file in the same order.
 */
function feFuUpload(array $paths): Testable
{
    $files = array_map(
        fn (string $path): UploadedFile => UploadedFile::fake()->create(basename($path), 4, 'application/pdf'),
        $paths,
    );

    return feFuComponent()
        ->set('uploadRelativePaths', $paths)
        ->set('files', $files);
}

function feFuTree(): array
{
    return Media::query()
        ->where('collection_name', UploadRules::collection())
        ->where('model_type', (new Folder)->getMorphClass())
        ->orderBy('id')
        ->get()
        ->map(function (Media $media): string {
            $names = [];
            $folder = Folder::query()->find($media->model_id);

            while ($folder) {
                $names[] = $folder->name;
                $folder = $folder->parent;
            }

            return implode('/', array_reverse($names)).'/'.$media->file_name;
        })
        ->all();
}

it('rebuilds the folders a folder upload describes', function (): void {
    feFuUpload([
        'Reports/2025/january.pdf',
        'Reports/2025/february.pdf',
        'Reports/notes.pdf',
    ]);

    expect(feFuTree())->toBe([
        'Library/Reports/2025/january.pdf',
        'Library/Reports/2025/february.pdf',
        'Library/Reports/notes.pdf',
    ]);

    // One "Reports" and one "2025", not one per file.
    expect(Folder::query()->where('name', 'Reports')->count())->toBe(1)
        ->and(Folder::query()->where('name', '2025')->count())->toBe(1);
});

it('reuses a folder that already exists', function (): void {
    $root = feFuRoot();
    $existing = Folder::query()->create(['name' => 'Reports', 'slug' => 'reports', 'parent_id' => $root->id]);

    feFuUpload(['Reports/january.pdf']);

    expect(Folder::query()->where('name', 'Reports')->count())->toBe(1)
        ->and((int) Media::query()->firstOrFail()->model_id)->toBe((int) $existing->id);
});

it('cannot walk out of the target folder', function (): void {
    // The paths come from the browser, so they are user input.
    feFuUpload(['../../escape/secret.pdf']);

    expect(feFuTree())->toBe(['Library/escape/secret.pdf'])
        ->and(Folder::query()->whereNull('parent_id')->count())->toBe(1);
});

it('treats a path without a folder like a plain upload', function (): void {
    feFuUpload(['loose.pdf']);

    expect(feFuTree())->toBe(['Library/loose.pdf'])
        ->and(Folder::query()->count())->toBe(1);
});

it('stops nesting at the configured depth instead of refusing the file', function (): void {
    config(['filament-file-explorer.folders.max_depth' => 3]);

    feFuUpload(['a/b/c/d/e/deep.pdf']);

    // Root is depth 0, so two levels fit; the file lands in the deepest folder
    // allowed rather than being dropped.
    expect(feFuTree())->toBe(['Library/a/b/deep.pdf']);
});

it('needs the mkdir ability to create the folders', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'mkdir' => false];
        }
    });

    feFuUpload(['Reports/january.pdf'])->assertForbidden();

    expect(Folder::query()->where('name', 'Reports')->count())->toBe(0);
});

it('forgets the paths after the upload', function (): void {
    $component = feFuComponent()
        ->set('uploadRelativePaths', ['Reports/january.pdf'])
        ->set('files', [UploadedFile::fake()->create('january.pdf', 4, 'application/pdf')]);

    // Left in place, the next plain upload would inherit them.
    expect($component->get('uploadRelativePaths'))->toBe([]);
});
