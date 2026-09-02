<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Authorizers\AllowAllAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Events\FileShared;
use Koassi\FilamentFileExplorer\Events\ShareRevoked;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Models\FileShare;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\FolderModel;
use Koassi\FilamentFileExplorer\Support\Sharing;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Tests\Fixtures\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function (): void {
    Storage::fake('public');

    $this->actingAs(new User(['id' => 1]));
});

function feShRootId(): int
{
    return app(FileExplorerRootResolver::class)->rootFolderId();
}

function feShComponent(): Testable
{
    return Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => feShRootId(),
    ]);
}

function feShMedia(string $name = 'report.pdf', ?int $folderId = null): Media
{
    $media = Media::query()->create([
        'model_type' => FolderModel::morphClass(),
        'model_id' => $folderId ?? feShRootId(),
        'collection_name' => UploadRules::collection(),
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

    // Real bytes, so the response is a real response.
    Storage::disk('public')->put($media->id.'/'.$name, '%PDF-1.4 x');

    return $media;
}

function feShShare(?Media $media = null): FileShare
{
    return app(Sharing::class)->create('library', feShRootId(), $media ?? feShMedia());
}

it('serves the file to someone with no session at all', function (): void {
    $share = feShShare();

    // The point of the feature: no authenticated user, no session.
    auth()->logout();

    $this->get(app(Sharing::class)->url($share))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('takes nothing but the token', function (): void {
    $share = feShShare();

    // The token is the whole path: no scope key, no media id, no conversion —
    // nothing that could be edited into a link for another file. Asserted
    // exactly, because "does not contain the other id" passes by luck when the
    // id is a single digit and the token is 64 random characters.
    expect(app(Sharing::class)->url($share))
        ->toBe(url('file-explorer/share/'.$share->token));
});

it('stops serving once revoked', function (): void {
    $share = feShShare();
    $url = app(Sharing::class)->url($share);

    $this->get($url)->assertOk();

    app(Sharing::class)->revoke($share);

    $this->get($url)->assertNotFound();

    // The row survives: a withdrawn share is still there to look at.
    expect(FileShare::query()->find($share->id)?->revoked_at)->not->toBeNull();
});

it('stops serving once expired', function (): void {
    $share = feShShare();
    $url = app(Sharing::class)->url($share);

    $this->get($url)->assertOk();

    $share->expires_at = now()->subMinute();
    $share->save();

    $this->get($url)->assertNotFound();
});

it('stops serving when the file is trashed, with nothing to update', function (): void {
    $media = feShMedia();
    $share = feShShare($media);
    $url = app(Sharing::class)->url($share);

    $this->get($url)->assertOk();

    app(Trash::class)->trashMedia($media);

    // The collection filter does it: a trashed file leaves the explorer's
    // collection, so containment fails on its own.
    $this->get($url)->assertNotFound();
});

it('stops serving when the file is moved out of the scope it was shared from', function (): void {
    $media = feShMedia();
    $share = feShShare($media);
    $url = app(Sharing::class)->url($share);

    $this->get($url)->assertOk();

    $elsewhere = FolderModel::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere', 'parent_id' => null]);
    $media->model_id = $elsewhere->id;
    $media->save();

    // Containment runs against the root recorded on the row — never against
    // anything derived from the media, which would only prove it sits under its
    // own ancestor.
    $this->get($url)->assertNotFound();
});

it('answers the same for a token that never existed', function (): void {
    $this->get(route('filament-file-explorer.share.show', ['token' => str_repeat('a', 64)]))
        ->assertNotFound();

    $this->get(route('filament-file-explorer.share.show', ['token' => 'short']))
        ->assertNotFound();
});

it('serves no conversion, whatever the query string asks for', function (): void {
    $share = feShShare();

    // A share is for the file that was shared, not a rendition named in a URL.
    $this->get(app(Sharing::class)->url($share).'?conversion=thumbnail')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('refuses to mint a link for media outside the root', function (): void {
    $elsewhere = FolderModel::query()->create(['name' => 'Outside', 'slug' => 'outside', 'parent_id' => null]);
    $media = feShMedia('outside.pdf', (int) $elsewhere->id);

    app(Sharing::class)->create('library', feShRootId(), $media);
})->throws(RuntimeException::class, 'does not belong to root');

it('hands back the same link rather than minting a second', function (): void {
    $media = feShMedia();

    $first = app(Sharing::class)->create('library', feShRootId(), $media);
    $second = app(Sharing::class)->create('library', feShRootId(), $media);

    expect($second->id)->toBe($first->id)
        ->and(FileShare::query()->count())->toBe(1);
});

it('caps the expiry at the configured ceiling', function (): void {
    config(['filament-file-explorer.share.max_ttl_days' => 3]);

    $share = app(Sharing::class)->create('library', feShRootId(), feShMedia(), ttlDays: 3650);

    expect($share->expires_at->diffInDays(now()->addDays(3)))->toBeLessThan(1);
});

it('allows a link that never expires only when config does', function (): void {
    config([
        'filament-file-explorer.share.max_ttl_days' => null,
        'filament-file-explorer.share.default_ttl_days' => null,
    ]);

    expect(app(Sharing::class)->create('library', feShRootId(), feShMedia())->expires_at)->toBeNull();
});

it('counts the views', function (): void {
    $share = feShShare();
    $url = app(Sharing::class)->url($share);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();

    expect(FileShare::query()->find($share->id)?->views)->toBe(2);
});

it('serves nothing when sharing is turned off', function (): void {
    $share = feShShare();
    $url = app(Sharing::class)->url($share);

    config(['filament-file-explorer.share.enabled' => false]);

    $this->get($url)->assertNotFound();
});

it('needs the share ability', function (): void {
    $media = feShMedia();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'download' => false];
        }
    });
    app()->forgetScopedInstances();

    // share follows download for an authorizer that says nothing about it.
    feShComponent()->call('shareFile', $media->id)->assertForbidden();
});

it('refuses to share media of another scope', function (): void {
    $elsewhere = FolderModel::query()->create(['name' => 'Outside', 'slug' => 'outside', 'parent_id' => null]);
    $media = feShMedia('outside.pdf', (int) $elsewhere->id);

    feShComponent()->call('shareFile', $media->id)->assertForbidden();
});

it('fires FileShared once, and ShareRevoked on withdrawal', function (): void {
    $heard = new ArrayObject;
    Event::listen(FileShared::class, fn (object $e) => $heard->append($e));
    Event::listen(ShareRevoked::class, fn (object $e) => $heard->append($e));

    $media = feShMedia();

    $component = feShComponent()->call('shareFile', $media->id);

    // Re-sharing something already shared is not a new event.
    $component->call('shareFile', $media->id);

    expect(iterator_to_array($heard))->toHaveCount(1);

    $component->call('revokeShare');

    $events = iterator_to_array($heard);

    expect($events)->toHaveCount(2)
        ->and($events[1])->toBeInstanceOf(ShareRevoked::class)
        ->and($events[0]->share->token)->toBe($events[1]->share->token);
});

it('reports the link to the panel', function (): void {
    $media = feShMedia();

    $component = feShComponent()->call('shareFile', $media->id);

    $state = $component->get('shareItem');

    expect($state['media_id'])->toBe($media->id)
        ->and($state['url'])->toContain('file-explorer/share/')
        ->and($state['expires_at'])->not->toBeNull();
});

it('offers the action on a file and renders the dialog', function (): void {
    $media = feShMedia();

    $html = feShComponent()->assertOk()->html();

    // Files only: no folder entry, and gated on the ability so an authorizer
    // that refuses sharing hides it.
    expect($html)->toContain("abilities.share && ctx.type === 'file'")
        ->toContain('$wire.shareFile(ctx.id)');

    $dialog = feShComponent()->call('shareFile', $media->id)->html();

    expect($dialog)->toContain(app(Sharing::class)->activeForMedia($media->id, 'library', feShRootId())->token)
        ->toContain('revokeShare');
});

it('hides the action when the ability is refused', function (): void {
    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'share' => false];
        }
    });
    app()->forgetScopedInstances();

    feShMedia();

    // The entry is in the markup but Alpine never shows it, so what matters is
    // that the ability reaching the JS is false.
    expect(feShComponent()->instance()->abilities()['share'])->toBeFalse();
});

it('drops the action entirely when sharing is off', function (): void {
    config(['filament-file-explorer.share.enabled' => false]);

    feShMedia();

    expect(feShComponent()->assertOk()->html())->not->toContain('$wire.shareFile');
});

it('marks a shared file in the listing, and stops once it is revoked', function (): void {
    $media = feShMedia();

    // Nothing to mark before the link exists.
    expect(feShComponent()->instance()->shareIndex())->toBe([]);

    $shared = feShComponent()->call('shareFile', $media->id);

    expect($shared->instance()->shareIndex())->toBe([$media->id => true])
        // Drawn on the item itself: a file anyone with a URL can open is not
        // something to find out by opening a dialog per row.
        ->and($shared->html())->toContain('fe-share-mark');

    $shared->call('revokeShare');

    expect(feShComponent()->instance()->shareIndex())->toBe([])
        ->and(feShComponent()->html())->not->toContain('fe-share-mark');
});

it('marks nothing when sharing is turned off', function (): void {
    $media = feShMedia();
    feShShare($media);

    config(['filament-file-explorer.share.enabled' => false]);

    expect(feShComponent()->instance()->shareIndex())->toBe([]);
});

it('asks once for a whole window of files', function (): void {
    $media = collect(range(1, 5))->map(fn (int $i): Media => feShMedia("file-{$i}.pdf"));
    $media->each(fn (Media $file) => feShShare($file));

    $component = feShComponent();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    // One query for the window, as the tag index does — asking per row is how a
    // folder of a hundred files becomes a hundred queries.
    expect($component->instance()->shareIndex())->toHaveCount(5)
        ->and($queries)->toBe(1);
});

it('lists every live link of the scope, with the file behind it', function (): void {
    $folder = FolderModel::query()->create(['name' => 'Docs', 'slug' => 'docs', 'parent_id' => feShRootId()]);
    $inside = feShMedia('inside.pdf', (int) $folder->id);
    $atRoot = feShMedia('root.pdf');

    feShShare($inside);
    feShShare($atRoot);

    $rows = feShComponent()->instance()->sharedItems();

    expect($rows)->toHaveCount(2)
        // Newest first, and each says where its file sits — a link is the
        // scope's, wherever in the tree that is.
        ->and($rows[0]['name'])->toBe('root.pdf')
        ->and($rows[1]['name'])->toBe('inside.pdf')
        ->and($rows[1]['path'])->toContain('Docs')
        ->and($rows[0]['url'])->toContain('file-explorer/share/');
});

it('leaves out a link that already reaches nothing', function (): void {
    $media = feShMedia();
    feShShare($media);

    expect(feShComponent()->instance()->sharedItems())->toHaveCount(1);

    app(Trash::class)->trashMedia($media);
    app()->forgetScopedInstances();

    // The link is dead on its own — that is how a share dies, with nothing kept
    // in step — so offering to stop it would be offering to stop nothing.
    expect(feShComponent()->instance()->sharedItems())->toBe([]);
});

it('lists no link made under another scope or another root', function (): void {
    $media = feShMedia();

    app(Sharing::class)->create('other-scope', feShRootId(), $media);

    expect(feShComponent()->instance()->sharedItems())->toBe([]);
});

it('stops one share from the dialog', function (): void {
    $first = feShShare(feShMedia('one.pdf'));
    $second = feShShare(feShMedia('two.pdf'));

    $component = feShComponent()
        ->call('openSharePanel')
        ->assertSet('showSharePanel', true)
        ->call('revokeShareById', $first->id);

    expect(FileShare::query()->find($first->id)?->revoked_at)->not->toBeNull()
        ->and(FileShare::query()->find($second->id)?->revoked_at)->toBeNull();

    // The dialog stays open on what is left: revoking several is one visit.
    $component->assertSet('showSharePanel', true);

    expect($component->instance()->sharedItems())->toHaveCount(1);
});

it('refuses to stop a link of another scope', function (): void {
    $media = feShMedia();
    $foreign = app(Sharing::class)->create('other-scope', feShRootId(), $media);

    feShComponent()->call('revokeShareById', $foreign->id);

    // The id arrives from the client, so the scope is proved rather than
    // trusted — as it is for every other id this component is handed.
    expect(FileShare::query()->find($foreign->id)?->revoked_at)->toBeNull();
});

it('closes the single-link dialog when its own link is stopped from the list', function (): void {
    $media = feShMedia();

    $component = feShComponent()->call('shareFile', $media->id);
    $share = app(Sharing::class)->activeForMedia($media->id, 'library', feShRootId());

    $component->call('revokeShareById', $share->id)->assertSet('shareItem', null);
});

it('needs the share ability to open the list or stop anything', function (): void {
    $share = feShShare();

    app()->instance(FileExplorerAuthorizer::class, new class extends AllowAllAuthorizer
    {
        public function abilities(string $scopeKey, int $rootFolderId): array
        {
            return [...parent::abilities($scopeKey, $rootFolderId), 'share' => false];
        }
    });
    app()->forgetScopedInstances();

    feShComponent()->call('openSharePanel')->assertForbidden();
    feShComponent()->call('revokeShareById', $share->id)->assertForbidden();

    expect(FileShare::query()->find($share->id)?->revoked_at)->toBeNull();
});

it('offers the list from the toolbar, and only while sharing is on', function (): void {
    $toolbar = fn (string $html): string => (string) Str::between($html, 'class="fe-toolbar', 'class="fe-browser');

    expect($toolbar(feShComponent()->html()))->toContain('wire:click="openSharePanel"');

    config(['filament-file-explorer.share.enabled' => false]);

    expect($toolbar(feShComponent()->html()))->not->toContain('openSharePanel');
});

it('renders the list with a way to stop each link', function (): void {
    $media = feShMedia();
    feShShare($media);

    $html = feShComponent()->call('openSharePanel')->html();

    expect($html)->toContain('fe-share-row')
        ->toContain('report.pdf')
        ->toContain('revokeShareById');
});
