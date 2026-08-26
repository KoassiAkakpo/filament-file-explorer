<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Pages\Concerns\InteractsWithFileExplorer;
use Koassi\FilamentFileExplorer\Support\ActiveRoot;
use Koassi\FilamentFileExplorer\Support\StandaloneAccess;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;
use UnitEnum;

/**
 * Panel-level File Explorer page: no record, no resource.
 *
 * The scope key and root folder come from a FileExplorerRootResolver instead of
 * an Eloquent record, which is the only thing the Livewire component ever
 * needed. Extend this class (or the concrete
 * \Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer) to customise.
 */
abstract class StandaloneFileExplorerPage extends Page
{
    use InteractsWithFileExplorer;

    protected string $view = 'filament-file-explorer::filament.pages.file-explorer';

    public function mount(): void
    {
        $this->mountFileExplorer();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return StandaloneSettings::slug();
    }

    public static function getNavigationLabel(): string
    {
        return StandaloneSettings::navigationLabel()
            ?? __('filament-file-explorer::file-explorer.explorer');
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return StandaloneSettings::navigationIcon();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return StandaloneSettings::navigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return StandaloneSettings::navigationSort();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return StandaloneSettings::shouldRegisterNavigation();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function canAccess(): bool
    {
        return StandaloneAccess::granted();
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

    protected function getHeaderActions(): array
    {
        $filesPage = StandaloneSettings::filesPageClass();

        if ($filesPage === null || ! $filesPage::canAccess()) {
            return [];
        }

        // Registered and permitted is still not the same as routed: a panel with
        // `register_pages` off may have registered one of the pair by hand. A
        // url() closure would raise that from inside the header's render.
        $url = $this->fileExplorerCompanionUrl(fn (): string => $filesPage::getUrl());

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
