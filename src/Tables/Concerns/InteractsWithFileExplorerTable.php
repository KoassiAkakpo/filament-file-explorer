<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tables\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Events\FileDeleted;
use Koassi\FilamentFileExplorer\Events\FileTrashed;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\FolderTree;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Support\Trash;
use Koassi\FilamentFileExplorer\Support\Uploader;
use Koassi\FilamentFileExplorer\Support\UploadRules;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait InteractsWithFileExplorerTable
{
    abstract public function fileExplorerScopeKey(): string;

    abstract protected function fileExplorerRootFolderId(): int;

    abstract protected function fileExplorerExplorerUrl(?int $folderId = null): string;

    protected function fileExplorerMediaQuery(): Builder
    {
        $rootId = $this->fileExplorerRootFolderId();
        $folderIds = app(FolderTree::class)->descendantFolderIdsIncludingRoot($rootId);

        return Media::query()
            ->where('model_type', (new Folder)->getMorphClass())
            ->whereIn('model_id', $folderIds)
            ->where('collection_name', UploadRules::collection())
            ->latest('id');
    }

    public function configureFileExplorerTable(Table $table): Table
    {
        $scopeKey = $this->fileExplorerScopeKey();
        $rootId = $this->fileExplorerRootFolderId();
        $authorizer = app(FileExplorerAuthorizer::class);

        return $table
            ->query($this->fileExplorerMediaQuery())
            ->emptyStateHeading(__('filament-file-explorer::file-explorer.no_files'))
            ->emptyStateDescription(__('filament-file-explorer::file-explorer.open_explorer_hint'))
            ->emptyStateActions([
                Action::make('openExplorer')
                    ->label(__('filament-file-explorer::file-explorer.explorer'))
                    ->icon('heroicon-o-folder-open')
                    ->url(fn (): string => $this->fileExplorerExplorerUrl()),
            ])
            ->columns(StandaloneSettings::tableColumns([
                'preview' => ImageColumn::make('preview')
                    ->label(__('filament-file-explorer::file-explorer.preview'))
                    // Through the media route, which is where the ability and
                    // containment checks live. getUrl() returns a raw disk URL:
                    // on a public disk it served every image in the library to
                    // anyone holding the link, full size, checked by nothing.
                    ->getStateUsing(fn (Media $record): ?string => str_starts_with((string) $record->mime_type, 'image/')
                        ? route('filament-file-explorer.media.show', [
                            'scopeKey' => $scopeKey,
                            'media' => $record->id,
                            'conversion' => 'thumbnail',
                        ])
                        : null)
                    ->circular()
                    ->imageSize(36)
                    ->toggleable(),

                'file_name' => TextColumn::make('file_name')
                    ->label(__('filament-file-explorer::file-explorer.file_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(60),

                'size' => TextColumn::make('size')
                    ->label(__('filament-file-explorer::file-explorer.size'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => Number::fileSize((int) $state, precision: 1))
                    ->alignEnd(),

                'mime_type' => TextColumn::make('mime_type')
                    ->label(__('filament-file-explorer::file-explorer.type'))
                    ->badge()
                    ->formatStateUsing(function (Media $record): string {
                        $ext = strtoupper((string) pathinfo((string) $record->file_name, PATHINFO_EXTENSION));

                        return $ext !== '' ? $ext : ((string) $record->mime_type ?: '—');
                    })
                    ->color('gray')
                    ->toggleable(),

                'added_by' => TextColumn::make('added_by')
                    ->label(__('filament-file-explorer::file-explorer.added_by'))
                    // custom_properties is JSON, so there is nothing to sort or
                    // search on here; the uploader is resolved per row and
                    // memoised, one query per distinct uploader on the page.
                    ->getStateUsing(fn (Media $record): ?string => app(Uploader::class)->label($record))
                    ->placeholder('—')
                    ->toggleable(),

                'created_at' => TextColumn::make('created_at')
                    ->label(__('filament-file-explorer::file-explorer.created_at'))
                    ->sortable()
                    ->toggleable(),
            ]))
            ->recordActions([
                ActionGroup::make([
                    Action::make('download')
                        ->label(__('filament-file-explorer::file-explorer.download'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn (Media $record): string => route('filament-file-explorer.media.show', [
                            'scopeKey' => $scopeKey,
                            'media' => $record->id,
                            'download' => 1,
                        ]))
                        ->openUrlInNewTab()
                        ->visible(fn (): bool => app(Abilities::class)->allows($scopeKey, $rootId, 'download')),

                    Action::make('openInExplorer')
                        ->label(__('filament-file-explorer::file-explorer.open_in_explorer'))
                        ->icon('heroicon-o-folder-open')
                        ->url(fn (Media $record): string => $this->fileExplorerExplorerUrl((int) $record->model_id)),

                    Action::make('delete')
                        ->label(__('filament-file-explorer::file-explorer.delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (Media $record): bool => $authorizer->mediaDeleteState($scopeKey, $record)['allowed'])
                        ->action(function (Media $record) use ($scopeKey, $rootId): void {
                            // Through Trash, like the explorer's own delete.
                            // Calling $record->delete() here destroyed the file
                            // outright even with the trash on, so the same
                            // button meant two different things depending on
                            // which page it was pressed from.
                            if (Trash::enabled()) {
                                app(Trash::class)->trashMedia($record);

                                event(new FileTrashed($scopeKey, $rootId, auth()->user(), $record, withFolder: false));
                            } else {
                                $mediaId = (int) $record->id;
                                $name = (string) $record->name;
                                $fileName = (string) $record->file_name;
                                $folderId = (int) $record->model_id;
                                $size = (int) $record->size;

                                $record->delete();

                                event(new FileDeleted(
                                    $scopeKey,
                                    $rootId,
                                    auth()->user(),
                                    $mediaId,
                                    $name,
                                    $fileName,
                                    $folderId,
                                    $size,
                                    purgedFromTrash: false,
                                ));
                            }

                            Notification::make()
                                ->success()
                                ->title(Trash::enabled()
                                    ? trans_choice('filament-file-explorer::file-explorer.trash.moved', 1)
                                    : __('filament-file-explorer::file-explorer.deleted'))
                                ->send();
                        }),
                ])
                    ->label(__('filament-file-explorer::file-explorer.actions'))
                    ->button()
                    ->color('gray'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
