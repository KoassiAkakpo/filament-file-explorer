<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use BackedEnum;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\FilamentFileExplorerPlugin;
use Koassi\FilamentFileExplorer\Resolvers\GlobalRootResolver;
use UnitEnum;

/**
 * Reads standalone page settings from the panel's plugin instance when one is
 * available, falling back to config. Reading the plugin instead of mutating
 * global config keeps settings panel-scoped in multi-panel apps, while the
 * config fallback keeps everything working outside a panel (console, tests).
 */
final class StandaloneSettings
{
    public static function plugin(): ?FilamentFileExplorerPlugin
    {
        try {
            return FilamentFileExplorerPlugin::get();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function enabled(): bool
    {
        return (bool) (self::plugin()?->isStandaloneEnabled()
            ?? config('filament-file-explorer.standalone.enabled', true));
    }

    public static function scopeKey(): string
    {
        return (string) (self::plugin()?->getScopeKey()
            ?? config('filament-file-explorer.standalone.scope_key', 'library'));
    }

    public static function resolverClass(): string
    {
        return (string) (self::plugin()?->getRootResolverClass()
            ?? config('filament-file-explorer.standalone.resolver', GlobalRootResolver::class));
    }

    public static function rootName(): string
    {
        return (string) (self::plugin()?->getRootFolderName()
            ?? config('filament-file-explorer.standalone.root.name', 'Library'));
    }

    public static function rootSlug(): string
    {
        return (string) (self::plugin()?->getRootFolderSlug()
            ?? config('filament-file-explorer.standalone.root.slug', 'library'));
    }

    public static function slug(): string
    {
        return (string) (self::plugin()?->getStandaloneSlug()
            ?? config('filament-file-explorer.standalone.slug', 'file-explorer'));
    }

    public static function filesSlug(): string
    {
        return (string) (self::plugin()?->getStandaloneFilesSlug()
            ?? config('filament-file-explorer.standalone.files_slug', 'file-explorer/files'));
    }

    public static function hasFilesPage(): bool
    {
        return (bool) (self::plugin()?->hasStandaloneFilesPage()
            ?? config('filament-file-explorer.standalone.files_page', true));
    }

    /**
     * @return class-string
     */
    public static function explorerPageClass(): string
    {
        return self::plugin()?->getExplorerPageClass()
            ?? FileExplorer::class;
    }

    /**
     * @return class-string|null
     */
    public static function filesPageClass(): ?string
    {
        if (! self::hasFilesPage()) {
            return null;
        }

        return self::plugin()?->getFilesPageClass()
            ?? FileExplorerFiles::class;
    }

    public static function navigationLabel(): ?string
    {
        $label = self::plugin()?->getNavigationLabel()
            ?? config('filament-file-explorer.standalone.navigation.label');

        return filled($label) ? (string) $label : null;
    }

    public static function navigationIcon(): string|BackedEnum|null
    {
        return self::plugin()?->getNavigationIcon()
            ?? config('filament-file-explorer.standalone.navigation.icon', 'heroicon-o-folder');
    }

    public static function navigationGroup(): string|UnitEnum|null
    {
        return self::plugin()?->getNavigationGroup()
            ?? config('filament-file-explorer.standalone.navigation.group');
    }

    public static function navigationSort(): ?int
    {
        $sort = self::plugin()?->getNavigationSort()
            ?? config('filament-file-explorer.standalone.navigation.sort');

        return $sort === null ? null : (int) $sort;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (self::plugin()?->shouldRegisterStandaloneNavigation()
            ?? config('filament-file-explorer.standalone.navigation.enabled', true));
    }

    public static function shouldRegisterFilesNavigation(): bool
    {
        return (bool) (self::plugin()?->shouldRegisterStandaloneFilesNavigation()
            ?? config('filament-file-explorer.standalone.navigation.files_enabled', false));
    }
}
