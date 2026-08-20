<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Resolvers\PerUserRootResolver;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\Project;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectExplorerPage;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');

    $this->actingAs(new User(['id' => 1]));
});

function feAttachFile(Folder $folder, string $name = 'report.pdf', string $contents = 'file-contents'): Media
{
    $path = sys_get_temp_dir().'/'.uniqid('fetest_').'-'.$name;
    file_put_contents($path, $contents);

    return $folder->addMedia($path)
        ->usingName($name)
        ->usingFileName($name)
        ->toMediaCollection(UploadRules::collection());
}

function feStandaloneRoot(): Folder
{
    return Folder::query()->findOrFail(app(FileExplorerRootResolver::class)->rootFolderId());
}

function feShowUrl(string $scopeKey, Media $media, array $extra = []): string
{
    return route('filament-file-explorer.media.show', [
        'scopeKey' => $scopeKey,
        'media' => $media->id,
        ...$extra,
    ]);
}

it('serves a media file that belongs to the requested scope', function (): void {
    $media = feAttachFile(feStandaloneRoot());

    $response = $this->get(feShowUrl('library', $media));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toStartWith('inline')
        ->and($response->headers->get('content-length'))->toBe((string) strlen('file-contents'))
        ->and($response->streamedContent())->toBe('file-contents');
});

it('refuses a media file that is not under the root of the requested scope', function (): void {
    // The root used to be derived from the requested media by walking up to its
    // top-most ancestor, which made the containment check tautological: any
    // media from any tree passed it.
    $foreignRoot = app(FileExplorerManager::class)->createRoot('Other', 'other');
    $media = feAttachFile($foreignRoot, 'secret.pdf');

    $this->get(feShowUrl('library', $media))->assertForbidden();
});

it('refuses a scope key nothing in the request has any claim to', function (): void {
    $media = feAttachFile(feStandaloneRoot());

    $this->get(feShowUrl('someone-elses-scope', $media))->assertForbidden();
});

it('serves the standalone files of the scope the user owns', function (): void {
    config(['filament-file-explorer.standalone.resolver' => PerUserRootResolver::class]);

    $media = feAttachFile(feStandaloneRoot());

    $this->get(feShowUrl('library.1', $media))->assertOk();
});

it('does not let a user read the standalone files of another user', function (): void {
    config(['filament-file-explorer.standalone.resolver' => PerUserRootResolver::class]);

    // The root PerUserRootResolver would resolve for user 2.
    $victimRoot = app(FileExplorerManager::class)->ensureRoot('library-2', 'Library — 2');
    $media = feAttachFile($victimRoot, 'payslip.pdf');

    // Scope keys are guessable by design, so the URL alone must not grant
    // anything: user 1 has no claim to user 2's scope.
    $this->get(feShowUrl('library.2', $media))->assertForbidden();
});

it('refuses a media row that hangs off another model', function (): void {
    $media = feAttachFile(feStandaloneRoot());

    // Same model_id, different owner: containment alone would still pass.
    $media->forceFill(['model_type' => (new Project)->getMorphClass()])->save();

    $this->get(feShowUrl('library', $media))->assertForbidden();
});

it('refuses a media row from another collection', function (): void {
    $media = feAttachFile(feStandaloneRoot());

    $media->forceFill(['collection_name' => 'avatars'])->save();

    $this->get(feShowUrl('library', $media))->assertForbidden();
});

it('still honours the download ability', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'download' => false];
        }
    });

    $media = feAttachFile(feStandaloneRoot());

    $this->get(feShowUrl('library', $media))->assertForbidden();
});

it('sends an attachment when a download is asked for', function (): void {
    $media = feAttachFile(feStandaloneRoot());

    $response = $this->get(feShowUrl('library', $media, ['download' => 1]));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toStartWith('attachment')
        ->and($response->headers->get('content-disposition'))->toContain('report.pdf');
});

it('answers a range request so large files can be seeked', function (): void {
    $media = feAttachFile(feStandaloneRoot(), 'clip.mp4', 'abcdefghij');

    $response = $this->get(feShowUrl('library', $media), ['Range' => 'bytes=2-5']);

    $response->assertStatus(206);

    expect($response->headers->get('content-range'))->toBe('bytes 2-5/10')
        ->and($response->headers->get('accept-ranges'))->toBe('bytes')
        ->and($response->streamedContent())->toBe('cdef');
});

it('zips a folder that belongs to the requested scope', function (): void {
    $folder = Folder::query()->create([
        'name' => 'Invoices',
        'slug' => 'invoices',
        'parent_id' => feStandaloneRoot()->id,
    ]);
    feAttachFile($folder, 'a.pdf', 'A');

    $response = $this->get(route('filament-file-explorer.media.zip-folder', [
        'scopeKey' => 'library',
        'folder' => $folder->id,
    ]));

    $response->assertOk();
    $body = $response->streamedContent();

    expect($response->headers->get('content-type'))->toBe('application/zip')
        ->and($response->headers->get('content-disposition'))->toContain('invoices.zip')
        ->and((int) $response->headers->get('content-length'))->toBe(strlen($body))
        ->and(substr($body, 0, 2))->toBe('PK');
});

it('ignores a forged root when zipping a folder', function (): void {
    // zipFolder used to read the root from ?root=, so the caller could pick a
    // root that made its own target look contained.
    $foreignRoot = app(FileExplorerManager::class)->createRoot('Other', 'other');
    feAttachFile($foreignRoot, 'secret.pdf');

    $this->get(route('filament-file-explorer.media.zip-folder', [
        'scopeKey' => 'library',
        'folder' => $foreignRoot->id,
        'root' => $foreignRoot->id,
    ]))->assertForbidden();
});

it('flattens path traversal in names into a single archive segment', function (): void {
    $folder = Folder::query()->create([
        'name' => '../../etc',
        'slug' => 'traversal',
        'parent_id' => feStandaloneRoot()->id,
    ]);
    feAttachFile($folder, 'passwd', 'A');

    $body = $this->get(route('filament-file-explorer.media.zip-folder', [
        'scopeKey' => 'library',
        'folder' => $folder->id,
    ]))->streamedContent();

    $path = sys_get_temp_dir().'/'.uniqid('fezipassert_').'.zip';
    file_put_contents($path, $body);

    $zip = new ZipArchive;
    $zip->open($path);

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->statIndex($i)['name'];
    }

    $zip->close();
    @unlink($path);

    expect($entries)->toHaveCount(1);

    $segments = explode('/', $entries[0]);

    expect($segments)->toHaveCount(2)
        ->and($segments)->not->toContain('..')
        ->and($segments[0])->not->toStartWith('/')
        ->and($segments[1])->toBe('passwd');
});

it('zips a single media file', function (): void {
    $media = feAttachFile(feStandaloneRoot());

    $response = $this->get(route('filament-file-explorer.media.zip-media', [
        'scopeKey' => 'library',
        'media' => $media->id,
    ]));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('report.zip')
        ->and(substr($response->streamedContent(), 0, 2))->toBe('PK');
});

it('serves record-scoped media once a page registered its root', function (): void {
    $project = Project::create(['name' => 'Apollo']);
    $media = feAttachFile(Folder::query()->findOrFail($project->folder_id));
    $scopeKey = 'project.'.$project->getKey();

    $this->withSession(['filament-file-explorer.roots.1' => [$scopeKey => (int) $project->folder_id]])
        ->get(feShowUrl($scopeKey, $media))
        ->assertOk();
});

it('refuses record-scoped media when no page registered that scope', function (): void {
    $project = Project::create(['name' => 'Apollo']);
    $media = feAttachFile(Folder::query()->findOrFail($project->folder_id));

    $this->get(feShowUrl('project.'.$project->getKey(), $media))->assertForbidden();
});

it('registers the root of a record-scoped page for the media routes', function (): void {
    $project = Project::create(['name' => 'Apollo']);

    // The registry lives in the session, which a plain test request has not
    // started yet.
    request()->setLaravelSession(app('session.store'));

    (new ProjectExplorerPage)->mountForRecord($project);

    expect(ScopeRoots::resolve('project.'.$project->getKey()))->toBe((int) $project->folder_id);
});

it('keeps only the most recent scopes in the registry', function (): void {
    request()->setLaravelSession(app('session.store'));

    foreach (range(1, 70) as $i) {
        ScopeRoots::remember('project.'.$i, $i);
    }

    expect(ScopeRoots::resolve('project.70'))->toBe(70)
        ->and(ScopeRoots::resolve('project.7'))->toBe(7)
        ->and(ScopeRoots::resolve('project.6'))->toBeNull();
});
