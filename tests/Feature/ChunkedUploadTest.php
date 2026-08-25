<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\ChunkedUploads;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');

    // Slicing engages only when a request cannot carry a whole file, so every
    // test here makes that true: Livewire's own rule, set low.
    config([
        'filament-file-explorer.upload.chunk.enabled' => true,
        'filament-file-explorer.upload.chunk.size_kb' => 1,
        'livewire.temporary_file_upload.rules' => ['file', 'max:512'],
        'filament-file-explorer.upload.max_size_kb' => 51200,
        'media-library.max_file_size' => 50 * 1024 * 1024,
    ]);

    $this->actingAs(new User(['id' => 1]));
});

function feChRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

/**
 * The scope registry is what the media routes and this one resolve a root from,
 * and it is written once a page or the component has passed canAccess().
 */
function feChRegister(): int
{
    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feChRootId(),
    ]);

    return feChRootId();
}

function feChBegin(array $payload = []): TestResponse
{
    return test()->postJson(route('filament-file-explorer.upload.begin', ['scopeKey' => 'library']), array_merge([
        'folder' => feChRootId(),
        'name' => 'report.pdf',
        'size' => 3000,
    ], $payload));
}

function feChSlice(string $token, int $index, string $bytes): TestResponse
{
    return test()->post(
        route('filament-file-explorer.upload.chunk', ['token' => $token, 'index' => $index]),
        ['chunk' => UploadedFile::fake()->createWithContent('part', $bytes)],
    );
}

/**
 * Sends $content in the slices the server asked for, and returns the signed
 * reference the last one hands back.
 *
 * The size comes from `begin` rather than from the test, because that is where it
 * comes from in the browser: the server sizes a slice under whatever caps a
 * request, and a client picking its own would be a client guessing.
 */
function feChSend(string $content, string $name = 'report.pdf'): string
{
    feChRegister();

    $begin = feChBegin(['name' => $name, 'size' => strlen($content)])->assertSuccessful();

    $token = (string) $begin->json('token');
    $size = (int) $begin->json('chunk_bytes');
    $path = null;

    for ($index = 0; $index < (int) $begin->json('chunks'); $index++) {
        $path = feChSlice($token, $index, substr($content, $index * $size, $size))
            ->assertSuccessful()
            ->json('path');
    }

    return (string) $path;
}

/**
 * Content long enough to take several slices. The floor on a slice is a quarter
 * of a megabyte on purpose — below it the round trips cost more than the bytes —
 * so a test about ordering has to be bigger than that.
 */
function feChLongContent(int $slices = 3): string
{
    return '%PDF-1.4'."\n".str_repeat("\x00\x01\x02\xff", $slices * 70000);
}

/* ------------------------------------------------------------------- the guards */

it('needs a scope this session has been let into', function (): void {
    // A scope key nobody has passed canAccess() for. The standalone one resolves
    // read-only through its resolver, like the media routes do; anything else has
    // to have been registered by a page or the component first.
    test()->postJson(route('filament-file-explorer.upload.begin', ['scopeKey' => 'project.99']), [
        'folder' => feChRootId(),
        'name' => 'report.pdf',
        'size' => 3000,
    ])->assertForbidden();
});

it('needs the upload ability, not merely access', function (): void {
    feChRegister();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return array_merge(array_fill_keys(Abilities::ORIGINAL, true), ['upload' => false]);
        }
    });
    app()->forgetScopedInstances();

    // `upload`, not `download`: this is the media routes' check with the ability
    // that matches what the request is for.
    feChBegin()->assertForbidden();
});

it('refuses a folder outside the scope', function (): void {
    feChRegister();

    $outside = FolderModel::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'parent_id' => null]);

    // Folder ids arrive as user input here as they do everywhere else, so the
    // containment walk is the check — against the roots the scope resolved to,
    // never against anything derived from the folder itself.
    feChBegin(['folder' => $outside->id])->assertForbidden();
});

it('refuses a folder that is not there', function (): void {
    feChRegister();

    feChBegin(['folder' => 987654])->assertNotFound();
});

it('refuses a file over the ceiling before a byte of it arrives', function (): void {
    feChRegister();

    // Fifty megabytes is what this installation accepts once slicing takes the
    // request-shaped limits out of the answer. Sixty is not, and saying so now
    // beats saying it after sixty megabytes have crossed the wire.
    feChBegin(['size' => 60 * 1024 * 1024])->assertStatus(413);
});

it('refuses a file the quota has no room for', function (): void {
    feChRegister();
    config(['filament-file-explorer.quota.bytes' => 4096]);

    // Advisory — the gate that decides is the one in updatedFiles(), which knows
    // what the conflict policy frees. This is so nobody spends ten minutes
    // uploading a file that was never going to land.
    feChBegin(['size' => 100000])->assertStatus(413);
});

it('answers nothing at all when the transport is off', function (): void {
    feChRegister();
    config(['filament-file-explorer.upload.chunk.enabled' => false]);

    // The route is still registered — a cached route table must not depend on
    // php.ini — and the controller is what refuses.
    feChBegin()->assertNotFound();
});

it('hands out a token, a slice size and a count', function (): void {
    feChRegister();

    $begin = feChBegin(['size' => 3000])->assertSuccessful();

    expect($begin->json('token'))->toMatch(ChunkedUploads::TOKEN_PATTERN)
        ->and($begin->json('chunk_bytes'))->toBeGreaterThan(0)
        ->and($begin->json('chunks'))->toBe((int) ceil(3000 / $begin->json('chunk_bytes')));
});

/* ------------------------------------------------------------------ the slices */

it('refuses a slice for a token it never issued', function (): void {
    feChRegister();

    feChSlice(str_repeat('Z', 40), 0, 'x')->assertStatus(410);
});

it('refuses a token this session was not given', function (): void {
    feChRegister();
    $token = (string) feChBegin()->json('token');

    $this->flushSession();
    $this->actingAs(new User(['id' => 1]));
    feChRegister();

    // The token is not a bearer credential: it names a record only the session
    // that opened it holds, so a token that leaked into a log is worth nothing.
    feChSlice($token, 0, 'x')->assertStatus(410);
});

it('refuses a slice out of order', function (): void {
    feChRegister();

    $begin = feChBegin(['size' => strlen(feChLongContent())])->assertSuccessful();
    $token = (string) $begin->json('token');
    $size = (int) $begin->json('chunk_bytes');

    feChSlice($token, 0, str_repeat('a', $size))->assertSuccessful();

    // Retrying the slice already in would duplicate its bytes; skipping one
    // would leave a hole no length check could find.
    feChSlice($token, 2, str_repeat('c', $size))->assertStatus(409);
    feChSlice($token, 0, str_repeat('a', $size))->assertStatus(409);
});

it('refuses more bytes than the upload declared, and drops them', function (): void {
    feChRegister();

    $begin = feChBegin(['size' => 300000])->assertSuccessful();
    $token = (string) $begin->json('token');

    // The declared size is what the ceiling and the quota were checked against.
    // Without this cap a client could declare a kilobyte, pass both, and then
    // send a gigabyte — the early refusal would be the very thing that let it
    // through.
    feChSlice($token, 0, str_repeat('a', 400000))->assertStatus(413);

    // Refused *and* forgotten: an upload that cannot finish must not go on
    // occupying the disk while it waits to be abandoned.
    expect(app(ChunkedUploads::class)->record($token))->toBeNull()
        ->and(FileUploadConfiguration::storage()->allFiles(ChunkedUploads::directory()))->toBe([]);
});

it('joins the slices into exactly the file that was sent', function (): void {
    // Deliberately binary and deliberately not a whole multiple of the slice
    // size. Storage::append() joins with a newline, and this is the assertion
    // that catches it: one extra byte between every slice.
    $content = feChLongContent().'END';

    $signed = feChSend($content, 'report.pdf');
    $path = TemporaryUploadedFile::extractPathFromSignedPath($signed);

    expect($path)->not->toBeFalse();

    $stored = FileUploadConfiguration::storage()->get(FileUploadConfiguration::path((string) $path));

    expect($stored)->toBe($content)
        ->and(strlen((string) $stored))->toBe(strlen($content));
});

it('leaves behind exactly the temporary upload Livewire would have written', function (): void {
    $content = feChLongContent(2);
    $signed = feChSend($content, 'Quarterly Report.pdf');
    $path = (string) TemporaryUploadedFile::extractPathFromSignedPath($signed);

    // The sidecar is how Livewire reads the original name back — the hashed
    // filename carries none of it.
    $meta = json_decode((string) FileUploadConfiguration::storage()->get(FileUploadConfiguration::path($path).'.json'), true);

    expect($meta['name'])->toBe('Quarterly Report.pdf')
        ->and($meta['size'])->toBe(strlen($content));

    $file = TemporaryUploadedFile::createFromLivewire($path);

    expect($file->getClientOriginalName())->toBe('Quarterly Report.pdf')
        ->and($file->getSize())->toBe(strlen($content));
});

it('keeps its partials out of Livewire’s own temporary directory', function (): void {
    feChRegister();

    $begin = feChBegin(['size' => strlen(feChLongContent())])->assertSuccessful();
    feChSlice((string) $begin->json('token'), 0, str_repeat('a', (int) $begin->json('chunk_bytes')))
        ->assertSuccessful();

    // cleanupOldUploads() deletes anything in there older than a day, and a
    // partial is not a temporary upload until it is whole.
    expect(ChunkedUploads::directory())->not->toBe(FileUploadConfiguration::directory());

    $partials = FileUploadConfiguration::storage()->allFiles(ChunkedUploads::directory());

    expect($partials)->toHaveCount(1);
});

it('drops the bytes when the upload is cancelled', function (): void {
    feChRegister();

    $begin = feChBegin(['size' => strlen(feChLongContent())])->assertSuccessful();
    $token = (string) $begin->json('token');
    feChSlice($token, 0, str_repeat('a', (int) $begin->json('chunk_bytes')))->assertSuccessful();

    $this->post(route('filament-file-explorer.upload.cancel', ['token' => $token]))->assertSuccessful();

    expect(FileUploadConfiguration::storage()->allFiles(ChunkedUploads::directory()))->toBe([])
        ->and(app(ChunkedUploads::class)->record($token))->toBeNull();
});

it('forgets an upload that was left open too long', function (): void {
    feChRegister();
    config(['filament-file-explorer.upload.chunk.ttl_minutes' => 10]);

    $begin = feChBegin(['size' => strlen(feChLongContent())])->assertSuccessful();
    $token = (string) $begin->json('token');
    $size = (int) $begin->json('chunk_bytes');
    feChSlice($token, 0, str_repeat('a', $size))->assertSuccessful();

    Carbon::setTestNow(Carbon::now()->addMinutes(11));

    feChSlice($token, 1, str_repeat('b', $size))->assertStatus(410);

    Carbon::setTestNow();
});

it('clears abandoned partials when the next upload starts', function (): void {
    feChRegister();
    config(['filament-file-explorer.upload.chunk.ttl_minutes' => 10]);

    $begin = feChBegin(['size' => strlen(feChLongContent())])->assertSuccessful();
    feChSlice((string) $begin->json('token'), 0, str_repeat('a', (int) $begin->json('chunk_bytes')))
        ->assertSuccessful();

    Carbon::setTestNow(Carbon::now()->addMinutes(11));

    // Opportunistic, from begin(): bounded work on a request that is not hot,
    // and it needs no command to schedule. Livewire cleans its own directory the
    // same way.
    feChBegin(['size' => 1000])->assertSuccessful();

    // Nothing left: the abandoned partial is gone, and the upload just opened has
    // not written one yet — the file appears with its first slice.
    expect(FileUploadConfiguration::storage()->allFiles(ChunkedUploads::directory()))->toBe([]);

    Carbon::setTestNow();
});

/* ------------------------------------------------- and then the ordinary path */

it('lands the assembled file in the folder, through the path every upload takes', function (): void {
    $content = feChLongContent(2);
    $signed = feChSend($content, 'contract.pdf');

    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feChRootId(),
    ])->call('_finishUpload', 'files', [$signed], false);

    $media = Media::query()->where('collection_name', UploadRules::collection())->firstOrFail();

    // The whole design in one assertion: the transport ends by writing the
    // temporary file Livewire's own endpoint writes, so updatedFiles() runs with
    // nothing changed — same name, same size, same collection, same everything.
    expect($media->name)->toBe('contract.pdf')
        ->and($media->file_name)->toBe('contract.pdf')
        ->and((int) $media->size)->toBe(strlen($content))
        ->and((int) $media->model_id)->toBe(feChRootId());
});

it('still refuses a format the rules refuse, however it arrived', function (): void {
    $signed = feChSend('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'logo.svg');

    Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feChRootId(),
    ])->call('_finishUpload', 'files', [$signed], false);

    // Slicing is a transport and not a way in: the file is validated whole, by
    // the same rules, at the same place. SVG is refused on purpose — the media
    // route serves inline, and an SVG runs script in the panel's own origin.
    expect(Media::query()->where('collection_name', UploadRules::collection())->count())->toBe(0);
});
