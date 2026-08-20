<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\Uploader;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\Project;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');
});

function feByRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feByComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feByRoot()->id,
    ]);
}

function feByMedia(array $properties, string $name = 'report.pdf'): Media
{
    return Media::query()->create([
        'model_type' => (new Folder)->getMorphClass(),
        'model_id' => feByRoot()->id,
        'collection_name' => UploadRules::collection(),
        'name' => $name,
        'file_name' => $name,
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => $properties,
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

it('names the account that uploaded a file', function (): void {
    $user = User::query()->create(['name' => 'Aïcha Traoré', 'email' => 'aicha@example.test']);

    $this->actingAs($user);

    feByComponent()->set('files', [UploadedFile::fake()->createWithContent('note.pdf', '%PDF-1.4')]);

    $media = Media::query()->firstOrFail();

    expect(app(Uploader::class)->label($media))->toBe('Aïcha Traoré');
});

it('shows it in the inspector', function (): void {
    $user = User::query()->create(['name' => 'Aïcha Traoré']);

    $media = feByMedia([
        'uploaded_by_type' => User::class,
        'uploaded_by_id' => $user->id,
    ]);

    feByComponent()
        ->call('showInfo', 'file', $media->id)
        ->assertSet('infoItem.added_by', 'Aïcha Traoré')
        ->assertSee('Aïcha Traoré');
});

it('falls back to the email when there is no name', function (): void {
    $user = User::query()->create(['email' => 'nameless@example.test']);

    expect(app(Uploader::class)->label(feByMedia([
        'uploaded_by_type' => User::class,
        'uploaded_by_id' => $user->id,
    ])))->toBe('nameless@example.test');
});

it('keeps the trace when the account is gone', function (): void {
    $media = feByMedia([
        'uploaded_by_type' => User::class,
        'uploaded_by_id' => 4242,
    ]);

    expect(app(Uploader::class)->label($media))->toBe('#4242');
});

it('says nothing for a file that records no uploader', function (): void {
    expect(app(Uploader::class)->label(feByMedia([])))->toBeNull();
});

it('reads the uploader recorded by an older version', function (): void {
    $user = User::query()->create(['name' => 'Aïcha Traoré']);

    // user_id is what the first releases wrote, on its own.
    expect(app(Uploader::class)->label(feByMedia([
        'uploaded_by_type' => User::class,
        'user_id' => $user->id,
    ])))->toBe('Aïcha Traoré');
});

it('resolves a morph alias', function (): void {
    Relation::morphMap(['team' => Project::class]);

    $project = Project::query()->create(['name' => 'Gorée']);

    expect(app(Uploader::class)->label(feByMedia([
        'uploaded_by_type' => 'team',
        'uploaded_by_id' => $project->id,
    ])))->toBe('Gorée');
});

it('ignores a type that is not a model', function (): void {
    // The type is a class name read out of a JSON column.
    expect(app(Uploader::class)->label(feByMedia([
        'uploaded_by_type' => Trash::class,
        'uploaded_by_id' => 1,
    ])))->toBeNull();
});

it('costs one query per distinct uploader, not per file', function (): void {
    $user = User::query()->create(['name' => 'Aïcha Traoré']);

    $medias = collect(range(1, 5))->map(fn (int $i): Media => feByMedia([
        'uploaded_by_type' => User::class,
        'uploaded_by_id' => $user->id,
    ], "f{$i}.pdf"));

    $uploader = app(Uploader::class);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $medias->each(fn (Media $media) => $uploader->label($media));

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('lists the uploader in the files table', function (): void {
    Filament\Facades\Filament::setCurrentPanel('admin');

    $user = User::query()->create(['name' => 'Aïcha Traoré']);

    feByMedia([
        'uploaded_by_type' => User::class,
        'uploaded_by_id' => $user->id,
    ]);

    Livewire::test(FileExplorerFiles::class)
        ->assertOk()
        ->assertSee('Added by')
        ->assertSee('Aïcha Traoré');
});
