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
    /**
     * The views the explorer knows how to render. Kept here so the default and
     * the component's own validation cannot drift apart.
     */
    public const VIEW_MODES = ['grid', 'list', 'table', 'details', 'columns'];

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

    /**
     * Bytes a scope may hold, or null for no limit.
     */
    public static function quotaBytes(): ?int
    {
        $plugin = self::plugin();

        $bytes = $plugin?->hasQuota() === true
            ? $plugin->getQuotaBytes()
            : config('filament-file-explorer.quota.bytes');

        return is_numeric($bytes) && (int) $bytes > 0 ? (int) $bytes : null;
    }

    /**
     * Seconds between two automatic refreshes, or 0 for none.
     */
    public static function refreshSeconds(): int
    {
        $plugin = self::plugin();

        $seconds = $plugin?->hasRefreshInterval() === true
            ? $plugin->getRefreshSeconds()
            : config('filament-file-explorer.refresh.seconds');

        return is_numeric($seconds) && (int) $seconds > 0 ? max(5, (int) $seconds) : 0;
    }

    /**
     * View the explorer opens in until the user picks another.
     */
    public static function defaultViewMode(): string
    {
        $mode = self::plugin()?->getDefaultViewMode()
            ?? config('filament-file-explorer.view.default', 'grid');

        return in_array($mode, self::VIEW_MODES, true) ? (string) $mode : 'grid';
    }

    /**
     * How deep folders may nest, or null for no limit.
     */
    public static function maxFolderDepth(): ?int
    {
        $depth = self::plugin()?->getMaxFolderDepth()
            ?? config('filament-file-explorer.folders.max_depth');

        return is_numeric($depth) && (int) $depth > 0 ? (int) $depth : null;
    }

    /**
     * @param  array<string, mixed>  $columns
     * @return array<array-key, mixed>
     */
    public static function tableColumns(array $columns): array
    {
        $callback = self::plugin()?->getTableColumnsCallback();

        return $callback === null ? array_values($columns) : array_values($callback($columns));
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
