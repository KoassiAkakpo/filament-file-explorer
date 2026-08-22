<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer;

use BackedEnum;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorer;
use Koassi\FilamentFileExplorer\Filament\Pages\FileExplorerFiles;
use Koassi\FilamentFileExplorer\Widgets\StorageWidget;
use UnitEnum;

class FilamentFileExplorerPlugin implements Plugin
{
    protected ?string $authorizerClass = null;

    protected ?bool $standaloneEnabled = null;

    protected ?bool $registersPages = null;

    protected ?bool $hasFilesPage = null;

    /** @var class-string|null */
    protected ?string $explorerPageClass = null;

    /** @var class-string|null */
    protected ?string $filesPageClass = null;

    /** @var class-string|null */
    protected ?string $rootResolverClass = null;

    protected ?string $scopeKey = null;

    protected ?string $slug = null;

    protected ?string $filesSlug = null;

    protected ?string $rootFolderName = null;

    protected ?string $rootFolderSlug = null;

    protected ?string $navigationLabel = null;

    protected string|BackedEnum|null $navigationIcon = null;

    protected string|UnitEnum|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected ?bool $shouldRegisterNavigation = null;

    protected ?bool $shouldRegisterFilesNavigation = null;

    protected ?int $quotaBytes = null;

    // A quota of null means "no limit", so the value alone cannot say whether
    // the panel set one. Without this flag, ->quota(null) could not turn off a
    // limit that config had set.
    protected bool $quotaSet = false;

    protected ?int $refreshSeconds = null;

    protected bool $refreshSet = false;

    protected ?string $defaultViewMode = null;

    protected ?int $maxFolderDepth = null;

    protected ?\Closure $tableColumns = null;

    protected ?bool $storageWidgetEnabled = null;

    public function getId(): string
    {
        return 'filament-file-explorer';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    */

    public function authorizer(string $class): static
    {
        $this->authorizerClass = $class;

        return $this;
    }

    /**
     * Resolver that supplies the scope key and root folder for the standalone
     * page. Defaults to GlobalRootResolver (one shared root).
     */
    public function rootResolver(string $class): static
    {
        $this->rootResolverClass = $class;

        return $this;
    }

    public function scopeKey(string $scopeKey): static
    {
        $this->scopeKey = $scopeKey;

        return $this;
    }

    public function rootFolder(string $name, ?string $slug = null): static
    {
        $this->rootFolderName = $name;
        $this->rootFolderSlug = $slug ?? $this->rootFolderSlug;

        return $this;
    }

    public function slug(string $slug, ?string $filesSlug = null): static
    {
        $this->slug = $slug;
        $this->filesSlug = $filesSlug ?? ($slug.'/files');

        return $this;
    }

    public function explorerPage(string $class): static
    {
        $this->explorerPageClass = $class;

        return $this;
    }

    public function filesPage(string $class): static
    {
        $this->filesPageClass = $class;
        $this->hasFilesPage = true;

        return $this;
    }

    public function withoutFilesPage(): static
    {
        $this->hasFilesPage = false;

        return $this;
    }

    /**
     * Skip page registration entirely (keep the plugin for its authorizer and
     * record-scoped resource pages only).
     */
    public function withoutPages(): static
    {
        $this->registersPages = false;

        return $this;
    }

    public function disabled(): static
    {
        $this->standaloneEnabled = false;

        return $this;
    }

    /**
     * Bytes a scope may hold, or null for no limit. Panel-scoped, so two panels
     * over the same tree can cap it differently.
     */
    public function quota(?int $bytes): static
    {
        $this->quotaBytes = $bytes;
        $this->quotaSet = true;

        return $this;
    }

    /**
     * Seconds between two automatic refreshes, or null for none.
     */
    public function refreshEvery(?int $seconds): static
    {
        $this->refreshSeconds = $seconds;
        $this->refreshSet = true;

        return $this;
    }

    /**
     * View the explorer opens in until the user picks another: grid, list,
     * table or details.
     */
    public function defaultViewMode(string $mode): static
    {
        $this->defaultViewMode = $mode;

        return $this;
    }

    /**
     * How deep folders may nest in this panel.
     */
    public function maxFolderDepth(int $depth): static
    {
        $this->maxFolderDepth = $depth;

        return $this;
    }

    /**
     * Rewrites the columns of the flat files table. The callback receives the
     * default columns, keyed by name, and returns what to show:
     *
     *   ->tableColumns(fn (array $columns) => [...$columns, TextColumn::make(...)])
     *   ->tableColumns(fn (array $columns) => Arr::except($columns, ['preview']))
     */
    public function tableColumns(\Closure $callback): static
    {
        $this->tableColumns = $callback;

        return $this;
    }

    public function navigationLabel(?string $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function navigationIcon(string|BackedEnum|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function navigationGroup(string|UnitEnum|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function withoutNavigation(): static
    {
        $this->shouldRegisterNavigation = false;

        return $this;
    }

    public function withFilesNavigation(bool $condition = true): static
    {
        $this->shouldRegisterFilesNavigation = $condition;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getAuthorizerClass(): ?string
    {
        return $this->authorizerClass;
    }

    public function getRootResolverClass(): ?string
    {
        return $this->rootResolverClass;
    }

    public function getScopeKey(): ?string
    {
        return $this->scopeKey;
    }

    public function getStandaloneSlug(): ?string
    {
        return $this->slug;
    }

    public function getStandaloneFilesSlug(): ?string
    {
        return $this->filesSlug;
    }

    public function getRootFolderName(): ?string
    {
        return $this->rootFolderName;
    }

    public function getRootFolderSlug(): ?string
    {
        return $this->rootFolderSlug;
    }

    public function isStandaloneEnabled(): ?bool
    {
        return $this->standaloneEnabled;
    }

    public function hasStandaloneFilesPage(): ?bool
    {
        return $this->hasFilesPage;
    }

    /**
     * @return class-string
     */
    public function getExplorerPageClass(): string
    {
        return $this->explorerPageClass ?? FileExplorer::class;
    }

    /**
     * @return class-string
     */
    public function getFilesPageClass(): string
    {
        return $this->filesPageClass ?? FileExplorerFiles::class;
    }

    public function getNavigationLabel(): ?string
    {
        return $this->navigationLabel;
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        return $this->navigationIcon;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->navigationGroup;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function hasQuota(): bool
    {
        return $this->quotaSet;
    }

    public function getQuotaBytes(): ?int
    {
        return $this->quotaBytes;
    }

    public function hasRefreshInterval(): bool
    {
        return $this->refreshSet;
    }

    public function getRefreshSeconds(): ?int
    {
        return $this->refreshSeconds;
    }

    public function getDefaultViewMode(): ?string
    {
        return $this->defaultViewMode;
    }

    public function getMaxFolderDepth(): ?int
    {
        return $this->maxFolderDepth;
    }

    public function getTableColumnsCallback(): ?\Closure
    {
        return $this->tableColumns;
    }

    public function shouldRegisterStandaloneNavigation(): ?bool
    {
        return $this->shouldRegisterNavigation;
    }

    public function shouldRegisterStandaloneFilesNavigation(): ?bool
    {
        return $this->shouldRegisterFilesNavigation;
    }

    /*
    |--------------------------------------------------------------------------
    | Plugin lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * Puts the storage widget on the panel's dashboard.
     *
     * Opt-in, and separate from the quota: the widget is worth having with no
     * cap set at all — it is then simply how much the library holds.
     */
    public function storageWidget(bool $condition = true): static
    {
        $this->storageWidgetEnabled = $condition;

        return $this;
    }

    public function withoutStorageWidget(): static
    {
        return $this->storageWidget(false);
    }

    public function hasStorageWidget(): ?bool
    {
        return $this->storageWidgetEnabled;
    }

    public function register(Panel $panel): void
    {
        // Registered whatever the pages do: the widget decides for itself in
        // canView(), and a panel may want the figure without the explorer's own
        // page — the record-scoped mode registers no standalone page at all.
        // $this->storageWidgetEnabled first, and not StandaloneSettings: the
        // reader resolves the *panel's* plugin, which is not yet this one while
        // register() runs, so a fluent ->storageWidget() would be read as
        // silence and fall through to config. Same shape as the helpers below.
        if ($this->storageWidgetEnabled ?? (bool) config('filament-file-explorer.standalone.storage_widget', false)) {
            $panel->widgets([StorageWidget::class]);
        }

        if (! $this->registersStandalonePages()) {
            return;
        }

        $pages = [$this->getExplorerPageClass()];

        if ($this->registersFilesPage()) {
            $pages[] = $this->getFilesPageClass();
        }

        $panel->pages($pages);
    }

    public function boot(Panel $panel): void
    {
        if ($this->authorizerClass) {
            config(['filament-file-explorer.authorizer' => $this->authorizerClass]);
        }
    }

    protected function registersStandalonePages(): bool
    {
        $enabled = $this->standaloneEnabled
            ?? (bool) config('filament-file-explorer.standalone.enabled', true);

        $registers = $this->registersPages
            ?? (bool) config('filament-file-explorer.standalone.register_pages', true);

        return $enabled && $registers;
    }

    protected function registersFilesPage(): bool
    {
        return $this->hasFilesPage
            ?? (bool) config('filament-file-explorer.standalone.files_page', true);
    }
}
