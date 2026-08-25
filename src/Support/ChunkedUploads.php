<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Exceptions\ChunkedUploadFailed;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Sending one file as several requests, so `post_max_size` stops deciding how
 * big a file may be.
 *
 * The important thing about this class is what it is *not*: it is a **transport**
 * and not a second way to store a file. It ends by writing exactly the temporary
 * file Livewire's own upload endpoint would have written — same directory, same
 * hashed name, same `.json` sidecar — and hands back the same signed reference.
 * The browser then calls Livewire's `_finishUpload` with it, so `updatedFiles()`
 * runs unchanged: the same validation, the same conflict policy, the same quota
 * gate, the same versioning, the same `addMedia()`. A route that stored the file
 * itself would be a second write path, and the package has a standing rule about
 * those — they drift, and a demo or an upload is exactly where nobody notices.
 *
 * Two decisions carry the rest:
 *
 * - **Slices arrive in order, one at a time.** Parallel slices would need a
 *   received-set and a locked read-modify-write around it; in order, the state
 *   is one counter and an out-of-order slice is a 409 rather than a corrupted
 *   file. The bottleneck of a browser upload is the uplink, not concurrency.
 * - **The token is issued by the server and the path is derived from it.**
 *   Nothing the client sends ever reaches a filename. That is also where the
 *   ability, the containment walk and an early quota refusal happen — once, so
 *   the per-slice requests carry no decisions. The authoritative checks are
 *   still the ones in `updatedFiles()`, exactly where they were: this authorises
 *   to avoid wasting somebody's bandwidth, not to replace the guard at the
 *   write. Same split in time as a share link, for the same reason.
 */
final class ChunkedUploads
{
    /** What `Str::random(40)` produces, and the only shape a token may have. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9]{40}$/';

    /**
     * Upper bound on the slices of one file. A ceiling on the *count* as well as
     * on the total, so a client asking for a megabyte in four-kilobyte slices is
     * refused at the start rather than a quarter of a million requests later.
     */
    public const MAX_CHUNKS = 4096;

    /** One key holding every open upload of this session, so pruning can see them all. */
    private const SESSION_KEY = 'filament-file-explorer.chunks';

    public static function enabled(): bool
    {
        return UploadLimits::chunkingEngages();
    }

    /**
     * Where the partials sit: the disk Livewire keeps its temporary uploads on,
     * so the finished file is a rename rather than a copy.
     *
     * Reached through Livewire's own accessor everywhere below rather than
     * through `Storage::disk()`, because that accessor is what stands the disk up
     * under a test suite — writing to it directly would work in production and
     * fail in every test, which is the worst way round.
     */
    public static function disk(): string
    {
        return FileUploadConfiguration::disk();
    }

    public static function directory(): string
    {
        $directory = trim((string) config('filament-file-explorer.upload.chunk.directory', 'file-explorer-chunks'), '/');

        // Its own directory, never Livewire's: `cleanupOldUploads()` deletes
        // anything in there older than a day, and a partial is not a temporary
        // upload until it is whole.
        return $directory === '' || $directory === FileUploadConfiguration::directory()
            ? 'file-explorer-chunks'
            : $directory;
    }

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('filament-file-explorer.upload.chunk.ttl_minutes', 60));
    }

    /**
     * Opens an upload and says how to send it.
     *
     * @return array{token: string, chunk_bytes: int, chunks: int}
     */
    public function begin(string $scopeKey, int $rootFolderId, int $folderId, string $name, int $size): array
    {
        $this->prune();

        $chunkBytes = UploadLimits::chunkBytes();
        $chunks = max(1, (int) ceil($size / $chunkBytes));
        $token = Str::random(40);

        session([self::SESSION_KEY => array_merge($this->records(), [
            $token => [
                'scope_key' => $scopeKey,
                'root_folder_id' => $rootFolderId,
                'folder_id' => $folderId,
                'name' => $name,
                'chunks' => $chunks,
                'chunk_bytes' => $chunkBytes,
                // The declared size is used for the slice count and for the
                // early quota refusal, and for nothing after that: what the
                // bytes actually weigh is measured once they are here.
                'declared_size' => $size,
                'received' => 0,
                'bytes' => 0,
                'started_at' => Carbon::now()->toIso8601String(),
            ],
        ])]);

        return ['token' => $token, 'chunk_bytes' => $chunkBytes, 'chunks' => $chunks];
    }

    /**
     * The open upload behind a token, or null — expired ones are cleaned up and
     * read as absent, so a caller cannot tell "never existed" from "too late".
     *
     * @return array<string, mixed>|null
     */
    public function record(string $token): ?array
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $record = $this->records()[$token] ?? null;

        if (! is_array($record)) {
            return null;
        }

        if ($this->hasExpired($record)) {
            $this->discard($token);

            return null;
        }

        return $record;
    }

    /**
     * Appends one slice, and assembles the file when it was the last.
     *
     * @return array{received: int, chunks: int, complete: bool, path: string|null}
     */
    public function append(string $token, int $index, UploadedFile $chunk): array
    {
        $record = $this->record($token);

        if ($record === null) {
            throw ChunkedUploadFailed::gone();
        }

        // In order or not at all. Retrying the slice that is already in would
        // duplicate its bytes, and skipping one would leave a hole no length
        // check could find.
        if ($index !== (int) $record['received']) {
            throw ChunkedUploadFailed::outOfOrder((int) $record['received']);
        }

        $bytes = (int) $record['bytes'] + (int) $chunk->getSize();
        $limit = UploadLimits::maxUploadBytes();

        // Capped at what `begin` authorised, not merely at what the installation
        // accepts. The declared size is what the ceiling and the quota were
        // checked against, so without this a client could declare a kilobyte,
        // pass both, and then send a gigabyte — the early refusal would be
        // exactly the thing that let it through.
        $declared = (int) $record['declared_size'];

        if ($bytes > $declared || ($limit !== null && $bytes > $limit)) {
            $this->discard($token);

            throw ChunkedUploadFailed::tooLarge($limit ?? $declared);
        }

        $this->appendBytes($token, $chunk);

        $record['received'] = (int) $record['received'] + 1;
        $record['bytes'] = $bytes;

        $complete = $record['received'] >= (int) $record['chunks'];

        if (! $complete) {
            session([self::SESSION_KEY => array_merge($this->records(), [$token => $record])]);

            return [
                'received' => (int) $record['received'],
                'chunks' => (int) $record['chunks'],
                'complete' => false,
                'path' => null,
            ];
        }

        return [
            'received' => (int) $record['received'],
            'chunks' => (int) $record['chunks'],
            'complete' => true,
            'path' => $this->assemble($token, $record),
        ];
    }

    /**
     * Turns the finished partial into the temporary upload Livewire would have
     * written, and returns the signed reference its own endpoint returns.
     */
    private function assemble(string $token, array $record): string
    {
        $storage = FileUploadConfiguration::storage();

        $name = (string) $record['name'];
        $extension = $this->safeExtension($name);
        $hashName = Str::random(40).($extension === '' ? '' : '.'.$extension);
        $destination = FileUploadConfiguration::path($hashName);

        // The sidecar Livewire reads the original name, type and size back out
        // of. Written before the file moves, so a reader can never find the file
        // without it.
        $storage->put($destination.'.json', (string) json_encode([
            'name' => $name,
            'type' => $storage->mimeType($this->partialPath($token)),
            'size' => (int) $record['bytes'],
            'hash' => $hashName,
        ]));

        $storage->move($this->partialPath($token), $destination);

        $this->forget($token);

        return TemporaryUploadedFile::signPath($hashName);
    }

    /**
     * Drops an upload and its bytes — a cancel, an expiry, or a refusal.
     */
    public function discard(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return;
        }

        FileUploadConfiguration::storage()->delete($this->partialPath($token));

        $this->forget($token);
    }

    /**
     * Clears out what was started and never finished — a closed tab, a lost
     * connection, a refused slice.
     *
     * Opportunistic, from `begin()`: bounded work, on a request that is not hot,
     * and it needs no command to schedule. Livewire cleans its own temporary
     * directory the same way.
     */
    public function prune(): int
    {
        $records = $this->records();
        $expired = array_keys(array_filter(
            $records,
            fn ($record): bool => ! is_array($record) || $this->hasExpired($record),
        ));

        foreach ($expired as $token) {
            $this->discard((string) $token);
        }

        return count($expired);
    }

    /**
     * Appends to the growing partial.
     *
     * Raw append on the local file, not `Storage::append()`, which joins with a
     * newline — it would insert one byte between every slice and quietly corrupt
     * every binary file that went through it.
     */
    private function appendBytes(string $token, UploadedFile $chunk): void
    {
        $storage = FileUploadConfiguration::storage();
        $relative = $this->partialPath($token);

        if (! $storage->exists($relative)) {
            $storage->put($relative, '');
        }

        $target = $storage->path($relative);
        $source = $chunk->getRealPath();

        $in = @fopen($source, 'rb');
        $out = @fopen($target, 'ab');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }

            if (is_resource($out)) {
                fclose($out);
            }

            throw ChunkedUploadFailed::unwritable();
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function partialPath(string $token): string
    {
        // From a hash of the token rather than the token itself: the token is
        // ours and alphanumeric, and this is still the only string that becomes
        // a filename, so it is the one place worth making structurally unable to
        // carry a path.
        return self::directory().'/'.sha1($token).'.part';
    }

    /**
     * The extension the temporary file carries. It matters: when finfo cannot
     * decide, Flysystem falls back to the extension to answer `mimeType()`, and
     * that answer is what the upload rules and the kind filter read.
     */
    private function safeExtension(string $name): string
    {
        $extension = (string) pathinfo($name, PATHINFO_EXTENSION);

        return substr((string) preg_replace('/[^A-Za-z0-9]/', '', $extension), 0, 16);
    }

    private function hasExpired(array $record): bool
    {
        $startedAt = $record['started_at'] ?? null;

        if (! is_string($startedAt)) {
            return true;
        }

        return Carbon::parse($startedAt)->addMinutes(self::ttlMinutes())->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $records = session(self::SESSION_KEY, []);

        return is_array($records) ? $records : [];
    }

    private function forget(string $token): void
    {
        $records = $this->records();
        unset($records[$token]);

        session([self::SESSION_KEY => $records]);
    }
}
