<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Koassi\FilamentFileExplorer\Models\Concerns\HasFileExplorer;
use Koassi\FilamentFileExplorer\Pages\Concerns\InteractsWithFileExplorer;
use Koassi\FilamentFileExplorer\Support\HasFileExplorerModel;
use Koassi\FilamentFileExplorer\Tables\Concerns\InteractsWithFileExplorerTable;

abstract class FileExplorerFilesPage extends Page implements HasTable
{
    use InteractsWithFileExplorer;
    use InteractsWithFileExplorerTable;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected string $view = 'filament-file-explorer::filament.pages.file-explorer-files';

    public static function getNavigationLabel(): string
    {
        return __('filament-file-explorer::file-explorer.files');
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->mountFileExplorer();
    }

    public function table(Table $table): Table
    {
        return $this->configureFileExplorerTable($table);
    }

    public function fileExplorerScopeKey(): string
    {
        $record = HasFileExplorerModel::assert($this->getRecord());

        return $record->fileExplorerScopeKey();
    }

    protected function resolveFileExplorerRootFolderId(): int
    {
        return $this->resolveFileExplorerRootFolderIdFromRecord($this->getRecord());
    }

    protected function resolveFileExplorerRootFolderIdFromRecord(mixed $record): int
    {
        /** @var HasFileExplorer $record */
        $record = HasFileExplorerModel::assert($record);
        $id = $record->fileExplorerRootFolderId();

        abort_unless($id, 404);

        return $id;
    }

    protected function fileExplorerRootFolderId(): int
    {
        return $this->rootFolderId;
    }

    protected function fileExplorerExplorerPageName(): string
    {
        return 'files';
    }

    protected function fileExplorerExplorerUrl(?int $folderId = null): string
    {
        $url = static::getResource()::getUrl(
            $this->fileExplorerExplorerPageName(),
            ['record' => $this->getRecord()],
        );

        return $folderId ? $url.'?folder='.$folderId : $url;
    }

    protected function getHeaderActions(): array
    {
        // Same rule as the explorer page's own button: `make-page --list`
        // generates this page without its companion, and a closure resolved
        // during the header's render is past anything that could catch it.
        if (! static::getResource()::hasPage($this->fileExplorerExplorerPageName())) {
            return [];
        }

        $url = $this->fileExplorerCompanionUrl(fn (): string => $this->fileExplorerExplorerUrl());

        if ($url === null) {
            return [];
        }

        return [
            Action::make('explorer')
                ->label(__('filament-file-explorer::file-explorer.explorer'))
                ->icon('heroicon-o-folder-open')
                ->url($url),
        ];
    }
}
