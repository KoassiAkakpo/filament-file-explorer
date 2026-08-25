<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Models\FileVersion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Keeping what the `replace` conflict policy used to destroy.
 *
 * Uploading over a file wrote the new row and deleted the old one outright,
 * trash or no trash — the one place in the package where something was lost
 * without ever passing through the trash. This makes the same trade the trash
 * made: move aside rather than destroy.
 *
 * The mechanism is the trash's, for the reasons that made it work there. A
 * version's media row changes collection, so every listing, every containment
 * check and every media route already filters it out — a version is not a
 * second way to download a file, exactly as a trashed one is not. The bytes on
 * disk are untouched, so restoring is a database write rather than a copy.
 *
 * What is new is the *lineage*: several media rows are one file. Replacing
 * writes a new row, so identity cannot be the id, and it cannot be the folder
 * and the name either or a rename would cut the history in half. A lineage is
 * minted once, carried by every row of the chain, and never rewritten — which is
 * why rename, move, copy and the trash needed no changes at all.
 *
 * Bound `scoped`: it memoises one lineage's history, which the inspector asks
 * for on every render while the panel is open.
 */
final class Versions
{
    /**
     * Histories already read this request, keyed by lineage.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $cache = [];

    public static function enabled(): bool
    {
        return (bool) config('filament-file-explorer.versions.enabled', true);
    }

    /**
     * Where a version's bytes sit.
     *
     * Sharing the live collection would make the versions part of the folder
     * they are the history of; sharing the trash's would put them in the trash
     * view, where restoring means something else. Both are guarded rather than
     * documented, because a config file is not the place to learn this.
     */
    public static function collection(): string
    {
        $collection = (string) config('filament-file-explorer.versions.collection', 'file-explorer-versions');

        if ($collection === UploadRules::collection() || $collection === Trash::collection()) {
            return UploadRules::collection().'-versions';
        }

        return $collection;
    }

    /**
     * How many versions a file keeps behind it. At least one: a history that
     * keeps nothing is the feature turned off, and `enabled` is what says that.
     */
    public static function keep(): int
    {
        return max(1, (int) config('filament-file-explorer.versions.keep', 3));
    }

    /**
     * Moves $current aside so an incoming upload can take its place, and returns
     * the lineage the new row has to join — or null when versioning is off, which
     * is the caller's signal to delete as before.
     *
     * Nothing is written to disk and nothing is copied: the row changes
     * collection and the bytes stay where they are.
     */
    public function setAside(Media $current): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $line = $this->lineOf((int) $current->id);
        $lineage = $line?->lineage ?? (string) Str::uuid();
        $sequence = $line?->sequence ?? $this->nextSequence($lineage);

        FileVersion::query()->updateOrCreate(
            ['media_id' => (int) $current->id],
            ['lineage' => $lineage, 'sequence' => $sequence, 'replaced_at' => Carbon::now()],
        );

        $current->collection_name = self::collection();
        $current->save();

        unset($this->cache[$lineage]);

        return $lineage;
    }

    /**
     * Records $media as the live row of $lineage — the file the folder shows.
     */
    public function register(Media $media, string $lineage): void
    {
        FileVersion::query()->updateOrCreate(
            ['media_id' => (int) $media->id],
            ['lineage' => $lineage, 'sequence' => $this->nextSequence($lineage), 'replaced_at' => null],
        );

        unset($this->cache[$lineage]);
    }

    /**
     * Destroys everything past the last `keep()` versions of a lineage.
     *
     * The oldest go first, and the live row is never a candidate. This is the
     * one place in the feature where bytes are lost, which is why the ceiling is
     * a configured number rather than a judgement made here.
     */
    public function prune(string $lineage): int
    {
        $keep = self::keep();

        $ids = FileVersion::query()
            ->where('lineage', $lineage)
            ->whereNotNull('replaced_at')
            ->orderByDesc('sequence')
            ->pluck('media_id')
            ->slice($keep)
            ->all();

        if ($ids === []) {
            return 0;
        }

        $purged = 0;

        // Through the model, so Media Library removes the bytes and the deleted
        // listener drops the line — the same path a purge from the trash takes.
        foreach (Media::query()->whereIn('id', $ids)->get() as $media) {
            $media->delete();
            $purged++;
        }

        unset($this->cache[$lineage]);

        return $purged;
    }

    /**
     * What the inspector lists for a file: the versions behind it, newest first.
     *
     * @return list<array{id: int, sequence: int, size: int, size_label: string, name: string, replaced_at: string|null, added_by: string|null}>
     */
    public function history(int $mediaId): array
    {
        if (! self::enabled()) {
            return [];
        }

        $line = $this->lineOf($mediaId);

        if ($line === null) {
            return [];
        }

        return $this->cache[$line->lineage] ??= $this->readHistory($line->lineage);
    }

    /**
     * Makes a version current again, and pushes what was current into the
     * history — the same move as a replacement, in the other direction.
     *
     * Returns the media row now live, or null when the swap cannot be made: the
     * lineage has no live row (the file is trashed or deleted), or this row is
     * not one of its versions.
     */
    public function restore(Media $version): ?Media
    {
        if (! self::enabled() || $version->collection_name !== self::collection()) {
            return null;
        }

        $line = $this->lineOf((int) $version->id);

        if ($line === null || $line->replaced_at === null) {
            return null;
        }

        $liveLine = FileVersion::query()
            ->where('lineage', $line->lineage)
            ->whereNull('replaced_at')
            ->first();

        $live = $liveLine === null ? null : Media::query()->find($liveLine->media_id);

        // A file in the trash has no live row to swap with, and restoring a
        // version into a folder that no longer shows the file would put a file
        // back that the user deleted. The trash is where that decision is made.
        if (! $live instanceof Media || $live->collection_name !== UploadRules::collection()) {
            return null;
        }

        $name = (string) $live->name;
        $fileName = (string) $live->file_name;

        $liveLine->replaced_at = Carbon::now();
        $liveLine->save();

        $live->collection_name = self::collection();
        $live->save();

        // The restored row takes the live row's name rather than the one it
        // carried when it was set aside. A lineage is one file, and its name is
        // the live one: someone restoring last week's contract wants this
        // week's file to hold the older content, not to be renamed back to
        // whatever it was called then. Media Library moves the file on disk when
        // file_name changes, and each row has a directory of its own, so the two
        // sharing a name costs nothing.
        $version->collection_name = UploadRules::collection();
        $version->name = $name;
        $version->file_name = $fileName;
        $version->save();

        $line->sequence = $this->nextSequence($line->lineage);
        $line->replaced_at = null;
        $line->save();

        unset($this->cache[$line->lineage]);

        return $version;
    }

    /**
     * The media row a version restore would displace — what the caller has to
     * name in its event, read before the swap makes it the past.
     */
    public function liveOf(int $mediaId): ?Media
    {
        $line = $this->lineOf($mediaId);

        if ($line === null) {
            return null;
        }

        $liveLine = FileVersion::query()
            ->where('lineage', $line->lineage)
            ->whereNull('replaced_at')
            ->first();

        return $liveLine === null ? null : Media::query()->find($liveLine->media_id);
    }

    /**
     * Forgets a media row's line, and — when it was the live one — destroys the
     * history behind it.
     *
     * Called from a model listener rather than at each delete site, for the
     * reason the annotations are: the explorer's delete, the trash purge, the
     * files table and the purge command are four doors, and one that forgot
     * would leave bytes on the disk that nothing on any screen accounts for.
     */
    public function forgetMedia(int $mediaId): void
    {
        $line = $this->lineOf($mediaId);

        if ($line === null) {
            return;
        }

        $lineage = $line->lineage;
        $wasLive = $line->replaced_at === null;

        $line->delete();
        unset($this->cache[$lineage]);

        if (! $wasLive) {
            return;
        }

        // The file is gone, so its history is history of nothing. Deleting
        // through the model recurses once per version, and each of those is a
        // version rather than a live row, so it stops there.
        foreach (Media::query()
            ->whereIn('id', FileVersion::query()->where('lineage', $lineage)->pluck('media_id'))
            ->get() as $media) {
            $media->delete();
        }

        FileVersion::query()->where('lineage', $lineage)->delete();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return list<array{id: int, sequence: int, size: int, size_label: string, name: string, replaced_at: string|null, added_by: string|null}>
     */
    private function readHistory(string $lineage): array
    {
        $lines = FileVersion::query()
            ->where('lineage', $lineage)
            ->whereNotNull('replaced_at')
            ->orderByDesc('sequence')
            ->get();

        if ($lines->isEmpty()) {
            return [];
        }

        $media = Media::query()
            ->where('collection_name', self::collection())
            ->whereIn('id', $lines->pluck('media_id'))
            ->get()
            ->keyBy(fn (Media $row): int => (int) $row->id);

        $uploader = app(Uploader::class);
        $history = [];

        foreach ($lines as $line) {
            $row = $media->get($line->media_id);

            // A line whose bytes are gone is not a version any more. It can
            // only happen if something deleted the media row without the
            // listener running, and listing it would offer a restore that
            // cannot work.
            if (! $row instanceof Media) {
                continue;
            }

            $history[] = [
                'id' => (int) $row->id,
                'sequence' => (int) $line->sequence,
                'size' => (int) $row->size,
                'size_label' => Quota::format((int) $row->size),
                'name' => MediaLabel::display($row),
                'replaced_at' => $line->replaced_at?->format('Y/m/d H:i'),
                'added_by' => $uploader->label($row),
            ];
        }

        return $history;
    }

    private function lineOf(int $mediaId): ?FileVersion
    {
        return FileVersion::query()->where('media_id', $mediaId)->first();
    }

    private function nextSequence(string $lineage): int
    {
        return ((int) FileVersion::query()->where('lineage', $lineage)->max('sequence')) + 1;
    }
}
