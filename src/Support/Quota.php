<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Support\Number;
use Koassi\FilamentFileExplorer\Models\Folder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * How much of a scope's allowance its files already take.
 *
 * The cap is per root folder, not per application: with the per-user or
 * per-tenant resolver each scope gets its own allowance of the same size, which
 * is the only reading of a quota that means anything when the root is shared.
 *
 * Trashed files count. They still sit on the disk, and a trash that quietly
 * stopped counting would let anyone go over the cap by deleting and re-uploading.
 */
final class Quota
{
    /** @var array<int, int> */
    private array $used = [];

    /**
     * Bytes committed earlier in this request. An upload or a copy adds rows
     * one at a time, and re-querying the sum between each would be both slower
     * and, mid-loop, wrong.
     */
    private int $reserved = 0;

    public function limitBytes(): ?int
    {
        return StandaloneSettings::quotaBytes();
    }

    public function usedBytes(int $rootFolderId): int
    {
        $used = $this->used[$rootFolderId] ??= (int) Media::query()
            ->where('model_type', (new Folder)->getMorphClass())
            ->whereIn('collection_name', [UploadRules::collection(), Trash::collection()])
            ->whereIn('model_id', app(FolderTree::class)->descendantFolderIdsIncludingRoot($rootFolderId))
            ->sum('size');

        return $used + $this->reserved;
    }

    public function fits(int $rootFolderId, int $bytes): bool
    {
        $limit = $this->limitBytes();

        return $limit === null || ($this->usedBytes($rootFolderId) + max(0, $bytes)) <= $limit;
    }

    /**
     * Books $bytes against the allowance, or refuses them.
     */
    public function reserve(int $rootFolderId, int $bytes): bool
    {
        if (! $this->fits($rootFolderId, $bytes)) {
            return false;
        }

        $this->reserved += max(0, $bytes);

        return true;
    }

    /**
     * What the sidebar shows, or null when the scope has no cap.
     *
     * @return array{used: int, limit: int, percent: int, used_label: string, limit_label: string, warning: bool, full: bool}|null
     */
    public function state(int $rootFolderId): ?array
    {
        $limit = $this->limitBytes();

        if ($limit === null) {
            return null;
        }

        $used = $this->usedBytes($rootFolderId);
        $percent = (int) min(100, round($used / max(1, $limit) * 100));

        return [
            'used' => $used,
            'limit' => $limit,
            'percent' => $percent,
            'used_label' => self::format($used),
            'limit_label' => self::format($limit),
            'warning' => $percent >= 85,
            'full' => $used >= $limit,
        ];
    }

    public static function format(int $bytes): string
    {
        return Number::fileSize($bytes, precision: 1);
    }

    /**
     * Dropped whenever the listing is reset, i.e. whenever something may have
     * changed — same hook as FolderTree.
     */
    public function flush(): void
    {
        $this->used = [];
        $this->reserved = 0;
    }
}
