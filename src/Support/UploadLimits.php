<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * How big a file may actually be — which is not what `upload.max_size_kb` says.
 *
 * That setting defaults to 50 MB, and on a stock host **none** of it is
 * reachable. Five ceilings stack, and the lowest wins:
 *
 * - `upload.max_size_kb` — ours, 50 MB.
 * - `media-library.max_file_size` — Spatie's, **10 MB** by default. `addMedia()`
 *   throws `FileIsTooBig` past it.
 * - `livewire.temporary_file_upload.rules` — Livewire's, **12 MB** by default,
 *   validated in *its* controller before our component is ever reached.
 * - `upload_max_filesize` — PHP's, 2 MB on a default build.
 * - `post_max_size` — PHP's, 8 MB, and it applies to the whole request body, so
 *   ten 1 MB files sent together hit it even though each one is small.
 *
 * So the honest default ceiling was **2 MB** against a promise of 50, and a file
 * over it failed with whichever of the five spoke first: a 422 Livewire turned
 * into a generic "upload failed", or nothing at all when the web server dropped
 * the body before PHP saw it. Nothing on screen named a number.
 *
 * This class is the single reader of all five. The three request-shaped ones are
 * what chunking lifts — a chunk is a request, so slicing under `post_max_size`
 * makes it stop deciding — and the two per-file ones are what remains. That is
 * the whole design: `maxUploadBytes()` is the promise, `perRequestBytes()` is
 * what one request can carry, and when the second is smaller than the first the
 * difference is exactly what the chunk transport exists to cover.
 */
final class UploadLimits
{
    /**
     * Floor on a slice. Below this the round trips cost more than the bytes, and
     * a host whose `post_max_size` is genuinely under a quarter megabyte has a
     * problem this package cannot paper over.
     */
    public const MIN_CHUNK_BYTES = 262144;

    /**
     * Headroom left inside a request for everything that is not the slice: the
     * multipart envelope, the CSRF token, the cookie header. A slice sized to
     * exactly `post_max_size` is a slice that does not fit.
     */
    private const CHUNK_HEADROOM = 0.8;

    /**
     * Every ceiling, named, in bytes — null where the setting expresses no
     * limit. Ordered as they apply, and read by the diagnostics as well as by
     * the two methods below, so there is one list rather than one per question.
     *
     * @return array<string, int|null>
     */
    public static function constraints(): array
    {
        return [
            'filament-file-explorer.upload.max_size_kb' => self::positive(UploadRules::maxSizeKb() * 1024),
            'media-library.max_file_size' => self::positive((int) config('media-library.max_file_size', 0)),
            'livewire.temporary_file_upload.rules' => self::livewireRuleBytes(),
            'upload_max_filesize' => self::iniBytes('upload_max_filesize'),
            'post_max_size' => self::iniBytes('post_max_size'),
        ];
    }

    /**
     * The three that cap a *request* rather than a file, and so the three that
     * slicing makes irrelevant.
     *
     * @return list<string>
     */
    public static function requestConstraints(): array
    {
        return ['livewire.temporary_file_upload.rules', 'upload_max_filesize', 'post_max_size'];
    }

    /**
     * The most one request may carry, whatever it carries — one whole file
     * today, one slice once chunking engages. Null when nothing caps it.
     */
    public static function perRequestBytes(): ?int
    {
        $constraints = self::constraints();

        return self::lowest(array_map(
            fn (string $key): ?int => $constraints[$key],
            self::requestConstraints(),
        ));
    }

    /**
     * The largest single file this installation can really take.
     *
     * With chunking engaged the request-shaped ceilings drop out of the answer,
     * because no request ever carries the whole file. Without it they are as
     * binding as the rest — which is the state every install was in.
     */
    public static function maxUploadBytes(): ?int
    {
        $constraints = self::constraints();

        if (self::chunkingEngages()) {
            foreach (self::requestConstraints() as $key) {
                unset($constraints[$key]);
            }
        }

        return self::lowest(array_values($constraints));
    }

    /**
     * Which setting decides `maxUploadBytes()`, so a refusal can name the thing
     * to change instead of only the number it could not exceed.
     */
    public static function bindingConstraint(): ?string
    {
        $limit = self::maxUploadBytes();

        if ($limit === null) {
            return null;
        }

        $constraints = self::constraints();

        if (self::chunkingEngages()) {
            foreach (self::requestConstraints() as $key) {
                unset($constraints[$key]);
            }
        }

        foreach ($constraints as $name => $bytes) {
            if ($bytes === $limit) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether slicing is both switched on and of any use.
     *
     * It is of no use in two cases, and both matter. When nothing caps a request
     * there is nothing to slice under. And when the temporary upload disk is
     * remote the browser is already sending its bytes straight there, past this
     * application entirely — `post_max_size` never applied in the first place,
     * and slicing would only route the bytes back through the server we just
     * took them off.
     */
    public static function chunkingEngages(): bool
    {
        if (! self::chunkingEnabled() || self::uploadsGoStraightToStorage() || ! self::sliceableDisk()) {
            return false;
        }

        $perRequest = self::rawPerRequestBytes();

        if ($perRequest === null) {
            return false;
        }

        $perFile = self::lowest([
            self::positive(UploadRules::maxSizeKb() * 1024),
            self::positive((int) config('media-library.max_file_size', 0)),
        ]);

        // Nothing to cover when a whole file already fits in one request.
        return $perFile === null || $perFile > $perRequest;
    }

    public static function chunkingEnabled(): bool
    {
        return (bool) config('filament-file-explorer.upload.chunk.enabled', true);
    }

    /**
     * Whether slices can be appended to one growing file where the temporary
     * uploads live.
     *
     * They can on a local disk and nowhere else: appending bytes to an existing
     * object is not something Flysystem offers, and `Storage::append()` is
     * line-oriented — it would insert a newline between every slice and corrupt
     * every binary file that went through it. Storing each slice as its own
     * object and concatenating at the end would work anywhere, at the cost of
     * reading and rewriting the whole file once more; not worth it, because the
     * one case that needs it is a remote temporary disk, and a remote temporary
     * disk is already receiving the browser's bytes directly.
     *
     * Read here rather than in the chunk transport so that "can we slice" has
     * one answer, and the browser is told the truth before it tries.
     *
     * The disk name is resolved the way Livewire resolves it for the same kind of
     * question in `isUsingS3()`, and deliberately not through
     * `FileUploadConfiguration::disk()`: that one answers `tmp-for-tests` while a
     * test suite is running, so it would describe the harness rather than the
     * host — and a driver that is only ever missing in tests is a check that only
     * ever fails in tests.
     */
    public static function sliceableDisk(): bool
    {
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        return config('filesystems.disks.'.$disk.'.driver') === 'local';
    }

    /**
     * Whether the browser uploads to storage rather than to this application.
     *
     * Livewire answers this — it is *its* temporary disk the browser writes to,
     * and its own machinery that presigns the URL. Read through Livewire rather
     * than by inspecting the disk driver ourselves, so the two cannot come to
     * disagree about which path an upload is taking.
     */
    public static function uploadsGoStraightToStorage(): bool
    {
        return FileUploadConfiguration::isUsingS3() || FileUploadConfiguration::isUsingGCS();
    }

    /**
     * Whether files have to be sent one at a time.
     *
     * Livewire refuses `uploadMultiple` outright on a remote temporary disk
     * (`S3DoesntSupportMultipleFileUploads`), because it presigns one URL per
     * upload. The explorer used to call it unconditionally, so a host that
     * configured direct-to-storage could not upload **anything** — the button
     * threw, and the exception bypasses the view handler, so what the user got
     * was a dead click.
     */
    public static function singleFileUploads(): bool
    {
        return self::uploadsGoStraightToStorage();
    }

    /**
     * How big a slice is: under whatever caps a request, with room for the
     * envelope, and never under the floor.
     */
    public static function chunkBytes(): int
    {
        $configured = max(
            self::MIN_CHUNK_BYTES,
            (int) config('filament-file-explorer.upload.chunk.size_kb', 4096) * 1024,
        );

        $perRequest = self::rawPerRequestBytes();

        if ($perRequest === null) {
            return $configured;
        }

        return max(self::MIN_CHUNK_BYTES, min($configured, (int) floor($perRequest * self::CHUNK_HEADROOM)));
    }

    /**
     * What the browser is told, so it can refuse a file before sending a byte of
     * it and say which number it broke.
     *
     * @return array{max: int|null, per_request: int|null, chunk: int, chunked: bool, single: bool, limit_label: string|null, binding: string|null}
     */
    public static function forBrowser(): array
    {
        $max = self::maxUploadBytes();

        return [
            'max' => $max,
            'per_request' => self::perRequestBytes(),
            'chunk' => self::chunkBytes(),
            'chunked' => self::chunkingEngages(),
            'single' => self::singleFileUploads(),
            'limit_label' => $max === null ? null : Quota::format($max),
            'binding' => self::bindingConstraint(),
        ];
    }

    /**
     * The request ceiling as the settings state it, before `chunkingEngages()`
     * has an opinion — which is what breaks the circle between the two.
     */
    private static function rawPerRequestBytes(): ?int
    {
        return self::lowest([
            self::livewireRuleBytes(),
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ]);
    }

    /**
     * `max:N` out of Livewire's own validation rules, in kilobytes like every
     * Laravel `max` on a file.
     */
    private static function livewireRuleBytes(): ?int
    {
        foreach (FileUploadConfiguration::rules() as $rule) {
            if (! is_string($rule) || ! str_starts_with($rule, 'max:')) {
                continue;
            }

            return self::positive(((int) substr($rule, 4)) * 1024);
        }

        return null;
    }

    /**
     * A PHP ini size, which is written "8M" rather than in bytes.
     *
     * Zero and negative mean "no limit" for `post_max_size` and are not
     * meaningfully enforceable anywhere else, so both read as no opinion rather
     * than as a ceiling of nothing.
     */
    private static function iniBytes(string $directive): ?int
    {
        $raw = trim((string) ini_get($directive));

        if ($raw === '') {
            return null;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        $value *= match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return self::positive($value);
    }

    private static function positive(int $bytes): ?int
    {
        return $bytes > 0 ? $bytes : null;
    }

    /**
     * @param  array<int, int|null>  $values
     */
    private static function lowest(array $values): ?int
    {
        $known = array_values(array_filter($values, fn (?int $value): bool => $value !== null));

        return $known === [] ? null : min($known);
    }
}
