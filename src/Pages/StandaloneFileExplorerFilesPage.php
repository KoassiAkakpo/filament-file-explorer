<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Pages\Concerns\InteractsWithFileExplorer;
use Koassi\FilamentFileExplorer\Support\ActiveRoot;
use Koassi\FilamentFileExplorer\Support\StandaloneAccess;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use Koassi\FilamentFileExplorer\Tables\Concerns\InteractsWithFileExplorerTable;
use UnitEnum;

/**
 * Flat table of every file under the standalone root folder.
 */
abstract class StandaloneFileExplorerFilesPage extends Page implements HasTable
{
    use InteractsWithFileExplorer;
    use InteractsWithFileExplorerTable;
    use InteractsWithTable;

    protected string $view = 'filament-file-explorer::filament.pages.file-explorer-files';

    public function mount(): void
    {
        $this->mountFileExplorer();
    }

    public function table(Table $table): Table
    {
        return $this->configureFileExplorerTable($table);
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return StandaloneSettings::filesSlug();
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-file-explorer::file-explorer.files');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return 'heroicon-o-table-cells';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return StandaloneSettings::navigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = StandaloneSettings::navigationSort();

        return $sort === null ? null : $sort + 1;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return StandaloneSettings::shouldRegisterFilesNavigation();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function canAccess(): bool
    {
        return StandaloneSettings::hasFilesPage() && StandaloneAccess::granted();
    }

    protected static function fileExplorerRootResolver(): FileExplorerRootResolver
    {
        return app(FileExplorerRootResolver::class);
    }

    public function fileExplorerScopeKey(): string
    {
        return static::fileExplorerRootResolver()->scopeKey();
    }

    protected function resolveFileExplorerRootFolderId(): int
    {
        // A resolver offering several roots remembers which one the user was in.
        return ActiveRoot::idFor(static::fileExplorerRootResolver());
    }

    protected function fileExplorerRootFolderId(): int
    {
        return $this->rootFolderId;
    }

    protected function fileExplorerExplorerUrl(?int $folderId = null): string
    {
        $url = StandaloneSettings::explorerPageClass()::getUrl();

        return $folderId ? $url.'?folder='.$folderId : $url;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('explorer')
                ->label(__('filament-file-explorer::file-explorer.explorer'))
                ->icon('heroicon-o-folder-open')
                ->url(fn (): string => $this->fileExplorerExplorerUrl()),
        ];
    }
}
