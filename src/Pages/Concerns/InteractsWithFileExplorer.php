<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Pages\Concerns;

use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Support\ScopeRoots;

trait InteractsWithFileExplorer
{
    public int $rootFolderId = 0;

    abstract public function fileExplorerScopeKey(): string;

    abstract protected function resolveFileExplorerRootFolderId(): int;

    public function mountFileExplorer(): void
    {
        $this->rootFolderId = $this->resolveFileExplorerRootFolderId();

        $scopeKey = $this->fileExplorerScopeKey();

        abort_unless(
            app(FileExplorerAuthorizer::class)
                ->canAccess($scopeKey, $this->rootFolderId),
            403
        );

        // Media and zip URLs rendered by this page (and by its table) carry the
        // scope key only, so the routes serving them need a trustworthy record
        // of the root it maps to. See Support\ScopeRoots.
        ScopeRoots::remember($scopeKey, $this->rootFolderId);
    }

    /**
     * The URL of the companion page's header button, or null when that page is
     * not there to link to.
     *
     * **Resolved now, not inside the action's `url()` closure.** Filament
     * evaluates that closure while rendering the header, which is long past any
     * try/catch in `getHeaderActions()` — so a page the host chose not to
     * register threw `RouteNotFoundException` out of the middle of a Blade view
     * rather than simply not offering the button. `make-page --explorer` and
     * `--list` each generate exactly one of the pair, so that is not an exotic
     * configuration: it is one of the two documented ways to use the generator.
     *
     * The closure is the caller's because the two modes address their companion
     * differently — a resource page by page name, a standalone page by class —
     * and both can fail for the same reason.
     */
    protected function fileExplorerCompanionUrl(callable $resolve): ?string
    {
        try {
            $url = $resolve();
        } catch (\Throwable) {
            // The route is not registered. Nothing to link to, which is the
            // answer rather than an error.
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }
}
