<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Koassi\FilamentFileExplorer\Models\Concerns\HasFileExplorer;
use Koassi\FilamentFileExplorer\Pages\Concerns\InteractsWithFileExplorer;
use Koassi\FilamentFileExplorer\Pages\Concerns\ResolvesRecordRootFolder;
use Koassi\FilamentFileExplorer\Support\HasFileExplorerModel;

abstract class FileExplorerPage extends Page
{
    use InteractsWithFileExplorer;
    use InteractsWithRecord;
    use ResolvesRecordRootFolder;

    protected string $view = 'filament-file-explorer::filament.pages.file-explorer';

    public static function getNavigationLabel(): string
    {
        return __('filament-file-explorer::file-explorer.explorer');
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->mountFileExplorer();
    }

    public function fileExplorerScopeKey(): string
    {
        $record = $this->getRecord();

        if (HasFileExplorerModel::uses($record)) {
            /** @var HasFileExplorer $record */
            return $record->fileExplorerScopeKey();
        }

        return static::class.'.'.$record->getKey();
    }

    protected function resolveFileExplorerRootFolderId(): int
    {
        return $this->resolveFileExplorerRootFolderIdFromRecord($this->getRecord());
    }

    protected function fileExplorerFilesPageName(): string
    {
        return 'files-list';
    }

    protected function getHeaderActions(): array
    {
        $page = $this->fileExplorerFilesPageName();

        // Asked of the resource rather than of the router: `getPages()` is the
        // list the host actually registered, and `make-page --explorer`
        // generates this page without its companion.
        if (! static::getResource()::hasPage($page)) {
            return [];
        }

        $url = $this->fileExplorerCompanionUrl(fn (): string => static::getResource()::getUrl(
            $page,
            ['record' => $this->getRecord()],
        ));

        if ($url === null) {
            return [];
        }

        return [
            Action::make('filesList')
                ->label(__('filament-file-explorer::file-explorer.files'))
                ->icon('heroicon-o-table-cells')
                ->url($url),
        ];
    }
}
