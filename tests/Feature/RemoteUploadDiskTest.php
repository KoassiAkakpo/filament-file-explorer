<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Koassi\FilamentFileExplorer\Support\Uploads;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Uploads that never touched this server.
 *
 * With Livewire's temporary disk pointed at S3 the browser PUTs straight to
 * storage, which is the whole point — `post_max_size` never sees the bytes. What
 * comes back is a `TemporaryUploadedFile` whose `getRealPath()` is an S3 *key*,
 * and Media Library does `is_file()` on it.
 *
 * A real S3 round trip is not something this suite can make, so what is
 * simulated here is the one property that matters and the one that broke: a disk
 * whose `path()` answers with a key while its `readStream()` still hands over
 * bytes. That is exactly the shape of the S3 adapter, built out of the local one.
 */
beforeEach(function (): void {
    $this->realRoot = sys_get_temp_dir().'/fe-remote-'.bin2hex(random_bytes(6));
    mkdir($this->realRoot, 0777, true);

    $root = $this->realRoot;

    Storage::extend('fake-remote', function ($app, $config) use ($root): FilesystemAdapter {
        $adapter = new LocalFilesystemAdapter($root);

        // The bytes are reachable through the Flysystem side, and `path()` is
        // built from a prefix that is not a directory on this machine — which is
        // precisely how an S3 disk behaves.
        return new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => 'bucket-key-space']);
    });

    config(['filesystems.disks.fake-remote' => ['driver' => 'fake-remote']]);
});

afterEach(function (): void {
    if (is_dir($this->realRoot)) {
        array_map('unlink', glob($this->realRoot.'/*/*') ?: []);
        array_map('rmdir', glob($this->realRoot.'/*') ?: []);
        @rmdir($this->realRoot);
    }
});

/**
 * A temporary upload sitting on the remote disk, with the sidecar Livewire reads
 * the original name out of.
 */
function feRemoteUpload(string $name, string $content): TemporaryUploadedFile
{
    $hashName = 'staged-'.md5($name).'.'.pathinfo($name, PATHINFO_EXTENSION);

    $disk = Storage::disk('fake-remote');
    $disk->put(FileUploadConfiguration::path($hashName), $content);
    $disk->put(FileUploadConfiguration::path($hashName).'.json', (string) json_encode([
        'name' => $name,
        'type' => 'application/octet-stream',
        'size' => strlen($content),
        'hash' => $hashName,
    ]));

    return new TemporaryUploadedFile($hashName, 'fake-remote');
}

it('is the situation that used to break', function (): void {
    $file = feRemoteUpload('report.pdf', '%PDF-1.4'."\n".'body');

    // The premise, asserted rather than assumed: what Media Library would call
    // is_file() on is not a file, which is why addMedia() answered
    // FileDoesNotExist for an upload that had in fact arrived.
    expect(is_file((string) $file->getRealPath()))->toBeFalse()
        ->and($file->getClientOriginalName())->toBe('report.pdf');
});

it('stages a remote upload into a file Media Library can read', function (): void {
    $content = '%PDF-1.4'."\n".str_repeat('x', 500);
    $file = feRemoteUpload('report.pdf', $content);

    [$readable, $staged] = Uploads::readable($file);

    expect($staged)->not->toBeNull()
        ->and(is_file((string) $staged))->toBeTrue()
        ->and(file_get_contents((string) $staged))->toBe($content)
        ->and($readable->getClientOriginalName())->toBe('report.pdf')
        // test: true, the way UploadedFile::fake() builds one — there is no
        // $_FILES entry behind a staged copy, and without it every rule refuses
        // the file for being invalid rather than for what it is.
        ->and($readable->isValid())->toBeTrue()
        ->and($readable->getSize())->toBe(strlen($content));

    @unlink((string) $staged);
});

it('leaves a local temporary upload entirely alone', function (): void {
    // The default install, and the case that has to stay both free and
    // *identical*: the authoritative format check downstream runs on whatever
    // comes back from here, and for a local upload that has to be the very object
    // the validator already saw — otherwise the two answers are merely probably
    // equal, and a mime guesser disagreeing with Flysystem's would start refusing
    // files that used to pass.
    $storage = FileUploadConfiguration::storage();
    $storage->put(FileUploadConfiguration::path('local-upload.pdf'), '%PDF-1.4'."\n".'body');
    $storage->put(FileUploadConfiguration::path('local-upload.pdf').'.json', (string) json_encode([
        'name' => 'report.pdf',
        'type' => 'application/pdf',
        'size' => 13,
        'hash' => 'local-upload.pdf',
    ]));

    $file = new TemporaryUploadedFile('local-upload.pdf', FileUploadConfiguration::disk());

    expect(is_file((string) $file->getRealPath()))->toBeTrue();

    [$readable, $staged] = Uploads::readable($file);

    expect($staged)->toBeNull()
        ->and($readable)->toBe($file);
});

it('leaves a plain uploaded file alone too', function (): void {
    $file = UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.4');

    [$readable, $staged] = Uploads::readable($file);

    // The ordinary case has to stay free, and it has to stay *identical*: the
    // second format check downstream runs on this same object, so a copy here
    // would make the two answers merely probably equal.
    expect($staged)->toBeNull()
        ->and($readable)->toBe($file);
});

it('sniffs the type from the bytes, not from what the upload claimed', function (): void {
    // A PDF under a .png name, with a Content-Type of application/octet-stream in
    // the sidecar — which for a presigned PUT is a header the *browser* chose.
    $file = feRemoteUpload('logo.png', '%PDF-1.4'."\n".str_repeat('x', 200));

    [$readable, $staged] = Uploads::readable($file);

    // This is why staging beats addMediaFromDisk(): that adder takes the mime
    // type from the object's stored Content-Type, and the kind filter, the type
    // sort and the upload rules all read mime_type.
    expect($readable->getMimeType())->toBe('application/pdf');

    @unlink((string) $staged);
});

it('keeps the extension, because the mime guesser falls back to it', function (): void {
    $file = feRemoteUpload('notes.txt', 'just words');

    [$readable, $staged] = Uploads::readable($file);

    expect(strtolower((string) pathinfo((string) $staged, PATHINFO_EXTENSION)))->toBe('txt');

    @unlink((string) $staged);
});

it('refuses a format the rules refuse, on the bytes', function (): void {
    $svg = feRemoteUpload('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script/></svg>');

    [$readable, $staged] = Uploads::readable($svg);

    // SVG is refused on purpose: the media route serves files inline, and an SVG
    // runs script in the panel's own origin. Reading the client's own
    // Content-Type would have made that guarantee advisory.
    expect(UploadRules::isAllowedUpload($readable))->toBeFalse();

    @unlink((string) $staged);
});

it('accepts a format the rules accept, on the bytes', function (): void {
    $pdf = feRemoteUpload('report.pdf', '%PDF-1.4'."\n".str_repeat('x', 200));

    [$readable, $staged] = Uploads::readable($pdf);

    expect(UploadRules::isAllowedUpload($readable))->toBeTrue();

    @unlink((string) $staged);
});

it('says which upload it could not read back', function (): void {
    $file = feRemoteUpload('gone.pdf', '%PDF-1.4');

    Storage::disk('fake-remote')->delete(FileUploadConfiguration::path((string) $file->getFilename()));

    // Named, because the alternative is a stack trace about a stream, and the
    // one thing a host needs to know is which upload went missing.
    expect(fn () => Uploads::readable($file))->toThrow(RuntimeException::class);
});
