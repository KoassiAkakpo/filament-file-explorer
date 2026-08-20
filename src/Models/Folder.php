<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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

    public function getDepth(): int
    {
        return app(FolderTree::class)->getDepth($this);
    }
}
