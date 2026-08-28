<?php

declare(strict_types=1);

use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Livewire\FileExplorer as FileExplorerComponent;
use Koassi\FilamentFileExplorer\Support\UploadLimits;
use Livewire\Livewire;

/**
 * Five ceilings stack over an upload and the lowest wins. Four of them are not
 * ours, and none of them used to be visible from anywhere.
 */
function feLimits(array $config = []): void
{
    config(array_merge([
        'filament-file-explorer.upload.max_size_kb' => 51200,
        'filament-file-explorer.upload.chunk.enabled' => true,
        'media-library.max_file_size' => 10 * 1024 * 1024,
        'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:12288'],
    ], $config));
}

it('reads every ceiling that applies, not only its own', function (): void {
    feLimits();

    $constraints = UploadLimits::constraints();

    // Ours promises 50 MB. Media Library's is 10 and Livewire's is 12, and both
    // refuse before ours is ever consulted — Livewire's in its own controller,
    // Media Library's inside addMedia().
    expect($constraints['filament-file-explorer.upload.max_size_kb'])->toBe(51200 * 1024)
        ->and($constraints['media-library.max_file_size'])->toBe(10 * 1024 * 1024)
        ->and($constraints['livewire.temporary_file_upload.rules'])->toBe(12288 * 1024)
        ->and($constraints)->toHaveKeys(['upload_max_filesize', 'post_max_size']);
});

it('lets the lowest ceiling decide, and says which one it was', function (): void {
    feLimits([
        'media-library.max_file_size' => 4 * 1024 * 1024,
        'filament-file-explorer.upload.chunk.enabled' => false,
    ]);

    // Naming it is the point. A refusal that only gives a number leaves the host
    // hunting through four config files for which one it was.
    expect(UploadLimits::maxUploadBytes())->toBeLessThanOrEqual(4 * 1024 * 1024)
        ->and(UploadLimits::bindingConstraint())->not->toBeNull();
});

it('parses a php ini size rather than reading it as bytes', function (): void {
    feLimits();

    // "8M" is eight megabytes, not eight bytes. Read raw, post_max_size would
    // have looked like the lowest ceiling on every host alive.
    $post = UploadLimits::constraints()['post_max_size'];

    expect($post === null || $post >= 1024)->toBeTrue();
});

it('takes the request-shaped ceilings out of the answer once slicing engages', function (): void {
    feLimits([
        'livewire.temporary_file_upload.rules' => ['file', 'max:1024'],
        'media-library.max_file_size' => 200 * 1024 * 1024,
        'filament-file-explorer.upload.chunk.enabled' => true,
    ]);

    // Livewire's rule caps one *request*, and with slicing no request carries a
    // whole file — so it stops deciding how big a file may be. That is the whole
    // feature in one assertion.
    expect(UploadLimits::chunkingEngages())->toBeTrue()
        ->and(UploadLimits::maxUploadBytes())->toBe(51200 * 1024)
        ->and(UploadLimits::perRequestBytes())->toBeLessThan(UploadLimits::maxUploadBytes());
});

it('lets them decide again when slicing is off', function (): void {
    feLimits([
        'livewire.temporary_file_upload.rules' => ['file', 'max:1024'],
        'media-library.max_file_size' => 200 * 1024 * 1024,
        'filament-file-explorer.upload.chunk.enabled' => false,
    ]);

    // Which is the state every install was in: a promise of 50 MB and a real
    // ceiling of one.
    expect(UploadLimits::chunkingEngages())->toBeFalse()
        ->and(UploadLimits::maxUploadBytes())->toBe(1024 * 1024);
});

it('does not slice when a whole file already fits in one request', function (): void {
    feLimits([
        'filament-file-explorer.upload.max_size_kb' => 512,
        'media-library.max_file_size' => 512 * 1024,
        'livewire.temporary_file_upload.rules' => ['file', 'max:102400'],
    ]);

    // Nothing to cover. A transport that engaged anyway would be changing the
    // path of uploads that were already working, which is the one thing it must
    // not do.
    expect(UploadLimits::chunkingEngages())->toBeFalse();
});

it('sizes a slice under what a request can carry, with room for the envelope', function (): void {
    feLimits([
        'livewire.temporary_file_upload.rules' => ['file', 'max:2048'],
        'filament-file-explorer.upload.chunk.size_kb' => 8192,
    ]);

    $chunk = UploadLimits::chunkBytes();

    // A slice sized to exactly the limit is a slice that does not fit: the
    // multipart envelope, the CSRF field and the cookie header all travel with
    // it.
    expect($chunk)->toBeLessThan(2048 * 1024)
        ->and($chunk)->toBeGreaterThanOrEqual(UploadLimits::MIN_CHUNK_BYTES);
});

it('never slices below the floor', function (): void {
    feLimits(['filament-file-explorer.upload.chunk.size_kb' => 1]);

    // Under a quarter of a megabyte the round trips cost more than the bytes.
    expect(UploadLimits::chunkBytes())->toBe(UploadLimits::MIN_CHUNK_BYTES);
});

it('stands down when the browser uploads straight to storage', function (): void {
    feLimits([
        'livewire.temporary_file_upload.disk' => 'uploads-s3',
        'filesystems.disks.uploads-s3.driver' => 's3',
        'livewire.temporary_file_upload.rules' => ['file', 'max:1024'],
    ]);

    // post_max_size never sees those bytes, so there is nothing to slice under —
    // and slicing would route the upload back through the very server it was
    // just taken off.
    expect(UploadLimits::uploadsGoStraightToStorage())->toBeTrue()
        ->and(UploadLimits::chunkingEngages())->toBeFalse()
        ->and(UploadLimits::singleFileUploads())->toBeTrue();
});

it('refuses to slice onto a disk it cannot append to', function (): void {
    feLimits([
        'livewire.temporary_file_upload.disk' => 'weird',
        'filesystems.disks.weird.driver' => 'ftp',
        'livewire.temporary_file_upload.rules' => ['file', 'max:1024'],
    ]);

    // Appending bytes to an existing object is not something Flysystem offers,
    // and Storage::append() joins with a newline — it would put one byte between
    // every slice and corrupt every binary file that went through it.
    expect(UploadLimits::sliceableDisk())->toBeFalse()
        ->and(UploadLimits::chunkingEngages())->toBeFalse();
});

it('tells the browser the numbers and where to send the slices', function (): void {
    feLimits([
        'livewire.temporary_file_upload.rules' => ['file', 'max:1024'],
    ]);

    $limits = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => app(FileExplorerRootResolver::class)->rootFolderId(),
    ])->instance()->uploadLimits();

    expect($limits['chunked'])->toBeTrue()
        ->and($limits['begin_url'])->toContain('library')
        ->and($limits['chunk_url'])->toContain('file-explorer/upload')
        ->and($limits['limit_label'])->not->toBeNull();
});

it('tells the browser nothing to slice with when it is not slicing', function (): void {
    feLimits(['filament-file-explorer.upload.chunk.enabled' => false]);

    $limits = Livewire::test(FileExplorerComponent::class, [
        'scopeKey' => 'library',
        'rootFolderId' => app(FileExplorerRootResolver::class)->rootFolderId(),
    ])->instance()->uploadLimits();

    // One answer, from the side that owns it, instead of the page guessing from
    // a set of numbers.
    expect($limits['chunked'])->toBeFalse()
        ->and($limits['begin_url'])->toBeNull()
        ->and($limits['chunk_url'])->toBeNull();
});

/**
 * The report `filament-file-explorer:install` prints.
 *
 * `upload.max_size_kb` reads like the ceiling and is not one — Media Library's
 * 10 MB is usually what actually decides. A host who read 50 MB in our own
 * config file used to learn the real number from a user whose upload was
 * refused, which is the worst place to learn it.
 */
it('names the setting that really caps an upload', function (): void {
    config()->set('filament-file-explorer.upload.max_size_kb', 51200);
    config()->set('media-library.max_file_size', 10 * 1024 * 1024);
    config()->set('filament-file-explorer.upload.chunk.enabled', false);

    $this->artisan('filament-file-explorer:install')
        ->expectsOutputToContain('media-library.max_file_size')
        ->assertSuccessful();
});

it('warns when our own setting promises more than the host delivers', function (): void {
    config()->set('filament-file-explorer.upload.max_size_kb', 51200);
    config()->set('media-library.max_file_size', 10 * 1024 * 1024);
    config()->set('filament-file-explorer.upload.chunk.enabled', false);

    $this->artisan('filament-file-explorer:install')
        ->expectsOutputToContain('upload.max_size_kb')
        ->assertSuccessful();
});

it('says nothing about a mismatch when our own setting is the binding one', function (): void {
    config()->set('filament-file-explorer.upload.max_size_kb', 1024);
    config()->set('media-library.max_file_size', 500 * 1024 * 1024);
    config()->set('filament-file-explorer.upload.chunk.enabled', true);

    // Ours is the lowest, so there is no gap between what it promises and what
    // the host will take, and nothing to interrupt for.
    expect(UploadLimits::bindingConstraint())->toBe('filament-file-explorer.upload.max_size_kb');
});
