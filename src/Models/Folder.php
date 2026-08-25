<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\Thumbnails;
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
        return $this->belongsTo(static::class, 'parent_id')->withTrashed();
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
    }

    /**
     * What media rows store in model_type.
     *
     * Answered for this class, never for a subclass: an application may swap in
     * its own model through `folders.model`, and letting the value follow the
     * subclass would orphan every media row already written — none of them
     * would pass the model_type check the containment guard makes. The morph map
     * still gets its say, so an alias keeps working.
     */
    public function getMorphClass(): string
    {
        $legacy = config('filament-file-explorer.morph_class');

        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        $alias = array_search(self::class, Relation::morphMap(), true);

        return $alias === false ? self::class : (string) $alias;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(UploadRules::collection());
    }

    /**
     * The thumbnail the explorer renders in place of the original.
     *
     * Config only, not a panel setting: conversions are registered on the model,
     * which knows nothing about the panel it is being browsed from. Everything
     * it reads goes through Support\Thumbnails, which is also what the views ask
     * whether to draw one — the two questions have to agree.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // Which kinds get one, from the *sniffed* mime type: Media Library picks
        // its image generator from the extension, so a file merely named .png
        // would be handed to GD. PDF and video are off unless the host asked for
        // them and the tooling is there — see Thumbnails for why that is two
        // conditions and not one.
        if ($media !== null && ! Thumbnails::wanted($media)) {
            return;
        }

        if ($media === null && ! Thumbnails::enabled()) {
            return;
        }

        $conversion = $this->addMediaConversion('thumbnail')
            ->fit(Fit::Crop, Thumbnails::width(), Thumbnails::height())
            // Both are ignored by the image generator and read by the other two,
            // so they cost nothing to set unconditionally. The first page of a
            // PDF is the one anybody would recognise it by; a second into a video
            // rather than zero, because the first frame of a great many videos is
            // black and a black square reads as a broken thumbnail.
            ->pdfPageNumber(Thumbnails::pdfPage())
            ->extractVideoFrameAtSecond(Thumbnails::videoSecond())
            ->performOnCollections(UploadRules::collection());

        // Not queued by default. Media Library queues conversions unless told
        // otherwise, and a host without a running worker would then never get a
        // thumbnail at all — the explorer would keep serving originals, which is
        // the whole thing this fixes. One 320px crop is cheap enough to do in
        // the upload request.
        //
        // A PDF or a video is not. Whoever turns those on is choosing between an
        // upload that waits on Ghostscript or ffmpeg and a queue that has to be
        // running; this setting is where they say which, and it is one setting
        // rather than one per kind because that is the same decision either way.
        if (Thumbnails::queued()) {
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
