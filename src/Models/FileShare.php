<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One public link to one file.
 *
 * It stores the authorization decision rather than the means to re-take it: a
 * request arriving on the share route has no session and no authenticated user,
 * so the ability was checked when the link was made. What is still checked on
 * every request is containment, against the root recorded here — see
 * Support\Sharing.
 *
 * @property string $token
 * @property int $media_id
 * @property string $scope_key
 * @property int $root_folder_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class FileShare extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'views' => 'integer',
        'media_id' => 'integer',
        'root_folder_id' => 'integer',
    ];

    public function getTable(): string
    {
        return (string) config('filament-file-explorer.share.table', 'file_explorer_shares');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether the link still stands. Says nothing about the file behind it,
     * which may have been moved, trashed or deleted since — Sharing::mediaFor()
     * is what answers that.
     */
    public function isLive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }
}
