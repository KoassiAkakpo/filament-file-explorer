<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Folder extends Model implements HasMedia
{
    use InteractsWithMedia;

    // The trash: a soft-deleted folder drops out of every Folder::query() the
    // explorer runs, which is what keeps the listing, the sidebar and the
    // search scope free of trashed rows.
    use SoftDeletes;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-file-explorer.folders.table', 'file_explorer_folders');
    }

    public function parent(): BelongsTo
    {
        // withTrashed so a trashed item can still say where it came from. A
        // live folder never has a trashed parent — trashing cascades — so
        // nothing else sees a difference.
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function getMorphClass(): string
    {
        $legacy = config('filament-file-explorer.morph_class');

        return is_string($legacy) && $legacy !== ''
            ? $legacy
            : parent::getMorphClass();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(UploadRules::collection());
    }

    /**
     * The thumbnail the explorer renders in place of the original.
     *
     * Config only, not a panel setting: conversions are registered on the model,
     * which knows nothing about the panel it is being browsed from.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        if (! (bool) config('filament-file-explorer.thumbnails.enabled', true)) {
            return;
        }

        // Media Library picks its image generator from the file's extension, so
        // anything merely *named* .png would be handed to GD and take the upload
        // down with it. The stored mime type is sniffed from the bytes, which is
        // the part worth trusting.
        if ($media !== null && ! str_starts_with((string) $media->mime_type, 'image/')) {
            return;
        }

        $conversion = $this->addMediaConversion('thumbnail')
            ->fit(
                Fit::Crop,
                (int) config('filament-file-explorer.thumbnails.width', 320),
                (int) config('filament-file-explorer.thumbnails.height', 320),
            )
            ->performOnCollections(UploadRules::collection());

        // Not queued by default. Media Library queues conversions unless told
        // otherwise, and a host without a running worker would then never get a
        // thumbnail at all — the explorer would keep serving originals, which is
        // the whole thing this fixes. One 320px crop is cheap enough to do in
        // the upload request.
        if ((bool) config('filament-file-explorer.thumbnails.queued', false)) {
            $conversion->queued();

            return;
        }

        $conversion->nonQueued();
    }

    public function getDepth(): int
    {
        return app(FolderTree::class)->getDepth($this);
    }
}
