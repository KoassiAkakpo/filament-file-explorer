<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Widgets;

use Filament\Widgets\Widget;
use Koassi\FilamentFileExplorer\Support\Abilities;
use Koassi\FilamentFileExplorer\Support\Quota;
use Koassi\FilamentFileExplorer\Support\StandaloneAccess;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

/**
 * What the standalone scope is holding, on the panel's dashboard.
 *
 * The same numbers the explorer's sidebar draws, in the one place someone looks
 * without going to the explorer first — which is the point: a quota nobody sees
 * until it refuses an upload is a quota that surprises people.
 *
 * It describes the **standalone** scope only. The record-scoped pages have one
 * root per record, so there is no single figure a dashboard widget could stand
 * for; an application that wants one knows which record it means and can read
 * `Quota` directly.
 */
class StorageWidget extends Widget
{
    protected string $view = 'filament-file-explorer::widgets.storage';

    protected int|string|array $columnSpan = 1;

    /**
     * Off unless asked for. A widget that appeared on someone's dashboard the
     * day they upgraded would be a surprise, and a dashboard is not ours.
     */
    public static function canView(): bool
    {
        if (! StandaloneSettings::storageWidget()) {
            return false;
        }

        $scope = self::scope();

        if ($scope === null) {
            return false;
        }

        // Both guards, as everywhere else: access to the scope, then the
        // ability. Reading how much a library holds is part of browsing it.
        return app(Abilities::class)->allows($scope['scopeKey'], $scope['rootFolderId'], 'browse');
    }

    /**
     * @return array{used_label: string, files: int, limit: array{used: int, limit: int, percent: int, used_label: string, limit_label: string, warning: bool, full: bool}|null, url: string|null}|null
     */
    public function getStorage(): ?array
    {
        $scope = self::scope();

        if ($scope === null) {
            return null;
        }

        $quota = app(Quota::class);
        $root = $scope['rootFolderId'];

        return [
            // The thresholds and the bar live on Quota::state(), which is what
            // the sidebar already draws — null here means the scope has no cap,
            // and the total on its own is still worth showing.
            'limit' => $quota->state($root),
            'used_label' => Quota::format($quota->usedBytes($root)),
            'files' => $quota->fileCount($root),
            'url' => self::explorerUrl(),
        ];
    }

    /**
     * @return array{scopeKey: string, rootFolderId: int}|null
     */
    protected static function scope(): ?array
    {
        return StandaloneAccess::scope();
    }

    /**
     * A way through to the explorer, when the page it would link to is one this
     * panel actually registered.
     */
    protected static function explorerUrl(): ?string
    {
        $page = StandaloneSettings::explorerPageClass();

        if (! method_exists($page, 'getUrl')) {
            return null;
        }

        try {
            return $page::getUrl();
        } catch (\Throwable) {
            // The page may not be registered on this panel — the host app is
            // free to register its own, or none. A widget is not the place to
            // fail over a link.
            return null;
        }
    }
}
