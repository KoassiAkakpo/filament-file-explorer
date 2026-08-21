<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Facades\FileExplorer;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\FolderModel;

/**
 * @mixin Model
 */
trait HasFileExplorer
{
    public static function bootHasFileExplorer(): void
    {
        static::creating(function (Model $model): void {
            if (! config('filament-file-explorer.auto_create_root', true)) {
                return;
            }

            /** @var Model&HasFileExplorer $model */
            $key = $model->fileExplorerFolderForeignKey();

            if (filled($model->getAttribute($key))) {
                return;
            }

            $folder = FileExplorer::createRoot(
                $model->fileExplorerRootFolderName(),
                $model->fileExplorerRootFolderSlug(),
            );

            $model->setAttribute($key, $folder->id);
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FolderModel::class(), $this->fileExplorerFolderForeignKey());
    }

    public function fileExplorerFolderForeignKey(): string
    {
        return 'folder_id';
    }

    public function fileExplorerRootFolderName(): string
    {
        foreach (['name', 'title', 'label'] as $attribute) {
            $value = $this->getAttribute($attribute);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return class_basename($this).' Files';
    }

    public function fileExplorerRootFolderSlug(): ?string
    {
        $slug = $this->getAttribute('slug');

        return filled($slug) ? (string) $slug : null;
    }

    public function fileExplorerScopeKeyPrefix(): string
    {
        return Str::kebab(class_basename($this));
    }

    public function fileExplorerScopeKey(): string
    {
        return $this->fileExplorerScopeKeyPrefix().'.'.$this->getKey();
    }

    public function fileExplorerRootFolderId(): ?int
    {
        $id = $this->getAttribute($this->fileExplorerFolderForeignKey());

        return $id !== null ? (int) $id : null;
    }

    public function ensureFileExplorerRoot(): Folder
    {
        if ($id = $this->fileExplorerRootFolderId()) {
            return FolderModel::query()->findOrFail($id);
        }

        $folder = FileExplorer::createRoot(
            $this->fileExplorerRootFolderName(),
            $this->fileExplorerRootFolderSlug(),
        );

        $this->forceFill([
            $this->fileExplorerFolderForeignKey() => $folder->id,
        ])->save();

        return $folder;
    }
}
