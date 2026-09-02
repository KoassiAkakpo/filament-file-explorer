<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Models\FileShare;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Public links to single files, and the one place they are made or resolved.
 *
 * A request on the share route has no session and no authenticated user, so the
 * two guards the rest of the package leans on cannot both run. They are split in
 * time instead:
 *
 * - **The ability is checked when the link is made**, by the caller, and the
 *   decision is what the row records. There is nobody to ask afterwards.
 * - **Containment is checked on every request**, against the root recorded on
 *   the row — never against anything derived from the media, which would only
 *   prove the file sits under its own ancestor.
 *
 * That split is the whole design, and it is what makes a link stop working when
 * the file is moved out of scope, trashed or deleted: the collection filter and
 * the containment walk both fail on their own, with no extra bookkeeping.
 */
final class Sharing
{
    public static function enabled(): bool
    {
        return (bool) config('filament-file-explorer.share.enabled', true);
    }

    /**
     * @throws RuntimeException when the media does not belong to the scope
     */
    public function create(
        string $scopeKey,
        int $rootFolderId,
        Media $media,
        ?int $ttlDays = null,
        ?Authenticatable $actor = null,
    ): FileShare {
        // The caller has checked the ability; this checks the file really is the
        // scope's, so a link can never be minted for something out of it.
        if (app(MediaScope::class)->folderUnderRoot($media, $rootFolderId) === null) {
            throw new RuntimeException('Refusing to share media '.$media->id.': it does not belong to root '.$rootFolderId.'.');
        }

        $existing = $this->activeForMedia((int) $media->id, $scopeKey, $rootFolderId);

        // Sharing the same file twice hands back the same link rather than
        // growing a pile of live ones — revoking then has one thing to revoke.
        if ($existing !== null) {
            return $existing;
        }

        return FileShare::query()->create([
            'token' => $this->freshToken(),
            'media_id' => (int) $media->id,
            'scope_key' => $scopeKey,
            'root_folder_id' => $rootFolderId,
            'created_by_type' => $actor ? $actor::class : null,
            'created_by_id' => $actor === null ? null : (string) $actor->getAuthIdentifier(),
            'expires_at' => $this->expiryFor($ttlDays),
        ]);
    }

    /**
     * The live share a token names, or null. Never leaks why: an expired token
     * and a token that never existed answer the same.
     */
    public function resolve(string $token): ?FileShare
    {
        if (! preg_match('/^[A-Za-z0-9]{32,64}$/', $token)) {
            return null;
        }

        $share = FileShare::query()->where('token', $token)->first();

        return $share instanceof FileShare && $share->isLive() ? $share : null;
    }

    /**
     * The file behind a share, still in scope. Null once it has been moved out,
     * trashed or deleted — none of which needs the share to be touched.
     */
    public function mediaFor(FileShare $share): ?Media
    {
        $media = Media::query()->find($share->media_id);

        if (! $media instanceof Media) {
            return null;
        }

        return app(MediaScope::class)->folderUnderRoot($media, $share->root_folder_id) === null
            ? null
            : $media;
    }

    public function activeForMedia(int $mediaId, string $scopeKey, int $rootFolderId): ?FileShare
    {
        return $this->liveQuery($scopeKey, $rootFolderId)
            ->where('media_id', $mediaId)
            ->latest('id')
            ->first();
    }

    /**
     * Which of these files carry a live link, in one query.
     *
     * The listing draws a window of rows and any of them may be shared; asking
     * per row is how a folder of a hundred files becomes a hundred queries —
     * the same reason Annotations::tagsFor() exists. The answer is keyed by id
     * so the views can ask `isset()` rather than search a list per row.
     *
     * @param  list<int>  $mediaIds
     * @return array<int, true>
     */
    public function activeMediaIds(array $mediaIds, string $scopeKey, int $rootFolderId): array
    {
        if (! self::enabled() || $mediaIds === []) {
            return [];
        }

        return array_fill_keys(
            $this->liveQuery($scopeKey, $rootFolderId)
                ->whereIn('media_id', $mediaIds)
                ->pluck('media_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            true,
        );
    }

    /**
     * Every live link of one scope and root, newest first.
     *
     * Says nothing about the files behind them: a share whose file has been
     * moved out, trashed or deleted is still a row here, and mediaFor() is what
     * answers whether it still reaches anything.
     *
     * @return Collection<int, FileShare>
     */
    public function liveForScope(string $scopeKey, int $rootFolderId): Collection
    {
        if (! self::enabled()) {
            return new Collection;
        }

        return $this->liveQuery($scopeKey, $rootFolderId)->latest('id')->get();
    }

    public function revoke(FileShare $share): void
    {
        if ($share->isRevoked()) {
            return;
        }

        // Kept rather than deleted: that a file was shared and then stopped
        // being shared is worth more than a row quietly disappearing.
        $share->revoked_at = now();
        $share->save();
    }

    public function url(FileShare $share): string
    {
        return route(
            (string) config('filament-file-explorer.share.routes.name', 'filament-file-explorer.share.').'show',
            ['token' => $share->token],
        );
    }

    public function recordView(FileShare $share): void
    {
        FileShare::query()->whereKey($share->getKey())->update([
            'views' => $share->views + 1,
            'last_viewed_at' => now(),
        ]);
    }

    /**
     * What "live" means, in SQL — the counterpart of FileShare::isLive(), and
     * the one place the two conditions are written. Three callers ask it, and a
     * fourth spelling of it would be a fourth chance to forget the expiry.
     *
     * @return Builder<FileShare>
     */
    private function liveQuery(string $scopeKey, int $rootFolderId): Builder
    {
        return FileShare::query()
            ->where('scope_key', $scopeKey)
            ->where('root_folder_id', $rootFolderId)
            ->whereNull('revoked_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Null means no expiry, which only happens when config asks for it.
     */
    private function expiryFor(?int $ttlDays): ?Carbon
    {
        $days = $ttlDays ?? config('filament-file-explorer.share.default_ttl_days', 7);

        if ($days === null) {
            $days = null;
        } else {
            $days = max(1, (int) $days);
        }

        $max = config('filament-file-explorer.share.max_ttl_days');

        if ($max !== null) {
            $max = max(1, (int) $max);
            // A cap set in config is a cap: a caller asking for longer, or for
            // no expiry at all, gets the cap.
            $days = $days === null ? $max : min($days, $max);
        }

        return $days === null ? null : now()->addDays($days);
    }

    private function freshToken(): string
    {
        do {
            $token = Str::random(64);
        } while (FileShare::query()->where('token', $token)->exists());

        return $token;
    }
}
