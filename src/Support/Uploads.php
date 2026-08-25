<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * Getting at an upload's bytes, wherever the browser put them.
 *
 * With Livewire's temporary disk left local — the default — an upload is a file
 * on this machine and everything downstream reads it directly. Point that disk
 * at S3 and the browser writes to S3 instead, which is the whole point of
 * direct-to-storage: `post_max_size` never sees the bytes. But then
 * `TemporaryUploadedFile::getRealPath()` answers with an S3 *key*, and Media
 * Library's `FileAdder` does `is_file()` on it and throws `FileDoesNotExist`. So
 * the explorer could not store what it had just successfully received.
 *
 * The fix is one staged copy, and the reason it is a copy rather than
 * `addMediaFromDisk()` is the interesting part. That adder has a terminal call of
 * its own, `toMediaCollectionFromRemote()`, so taking it would fork the write
 * path at both ends. And it takes the file's mime type from the object's stored
 * `Content-Type` — which, for a file the browser PUT straight to S3 through a
 * presigned URL, is a header **the browser chose**. `UploadRules` exists to keep
 * particular formats out (SVG is refused on purpose: the media route serves
 * inline, and an SVG runs script in the panel's own origin), and the kind filter
 * and the type sort both read `mime_type`. A client-declared mime type would
 * make all three advisory.
 *
 * Staging locally answers both at once: one write path, and the type is sniffed
 * from bytes exactly as it always was. The bytes cross this server once, as a
 * stream and not as a request body, so nothing in PHP's request limits applies —
 * the hop that those limits were about, browser to server, is still gone.
 */
final class Uploads
{
    /**
     * The upload as something that can be read from disk, and the temporary path
     * to delete afterwards — null when there was nothing to stage.
     *
     * @return array{0: UploadedFile, 1: string|null}
     */
    public static function readable(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        // The ordinary case, and the one that must stay free: a local temporary
        // upload is already a file, and this adds nothing to it.
        if ($path !== false && is_file($path)) {
            return [$file, null];
        }

        if (! $file instanceof TemporaryUploadedFile) {
            // Not ours to rescue: an UploadedFile whose path is not a file and
            // that Livewire did not make is broken in a way a copy cannot fix.
            return [$file, null];
        }

        return self::stage($file);
    }

    /**
     * Streams a remote temporary upload into a local file.
     *
     * @return array{0: UploadedFile, 1: string}
     */
    private static function stage(TemporaryUploadedFile $file): array
    {
        $name = $file->getClientOriginalName();
        $extension = (string) preg_replace('/[^A-Za-z0-9]/', '', (string) pathinfo($name, PATHINFO_EXTENSION));

        $temporary = tempnam(sys_get_temp_dir(), 'fe-upload-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create a temporary file to stage an upload into.');
        }

        // The extension is kept, because when finfo cannot decide, the mime
        // guesser falls back to it — and that answer is the one the upload rules
        // and the kind filter read.
        if ($extension !== '') {
            $named = $temporary.'.'.substr($extension, 0, 16);

            if (@rename($temporary, $named)) {
                $temporary = $named;
            }
        }

        $source = $file->readStream();
        $target = @fopen($temporary, 'wb');

        if ($source === null || $source === false || $target === false) {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
            }

            @unlink($temporary);

            throw new RuntimeException('Could not read the upload back from the temporary disk ['.$file->getFilename().'].');
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }

        // test: true, the way UploadedFile::fake() builds one — there is no
        // $_FILES entry behind a staged copy, and without it is_valid() fails
        // and every rule refuses the file.
        return [new UploadedFile($temporary, $name, null, null, true), $temporary];
    }
}
