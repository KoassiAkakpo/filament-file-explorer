<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Contracts\ResolvesExistingRoot;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The access check shared by the standalone pages.
 *
 * Filament calls canAccess() on every navigation render, so this runs on every
 * page of the panel. It is therefore deliberately read-only: resolving the root
 * the creating way would write a folder each time a menu is drawn, for every
 * authenticated user — including the ones this very check is about to deny.
 */
final class StandaloneAccess
{
    public static function granted(): bool
    {
        if (! StandaloneSettings::enabled()) {
            return false;
        }

        try {
            $resolver = app(FileExplorerRootResolver::class);

            return app(FileExplorerAuthorizer::class)->canAccess(
                $resolver->scopeKey(),
                self::rootFolderId($resolver),
            );
        } catch (HttpExceptionInterface) {
            // The per-user and per-tenant resolvers abort without a user or a
            // tenant, and the plugin is unavailable outside a panel: that is a
            // plain "not for this request", not something to report.
            return false;
        } catch (\Throwable $e) {
            // Anything else is a misconfiguration — a resolver class that does
            // not exist, a migration that has not run. Reported, because the
            // only symptom otherwise is a page silently missing from the menu.
            report($e);

            return false;
        }
    }

    /**
     * The scope key and root folder of the standalone explorer, once access has
     * been granted — or null.
     *
     * Read-only like granted(), and null rather than 0 when the scope has no
     * root yet: a caller who is not the page itself has nothing to measure and
     * must never be the one to create it.
     *
     * @return array{scopeKey: string, rootFolderId: int}|null
     */
    public static function scope(): ?array
    {
        if (! StandaloneSettings::enabled()) {
            return null;
        }

        try {
            $resolver = app(FileExplorerRootResolver::class);
            $scopeKey = $resolver->scopeKey();
            $rootFolderId = self::rootFolderId($resolver);

            if ($rootFolderId <= 0) {
                return null;
            }

            if (! app(FileExplorerAuthorizer::class)->canAccess($scopeKey, $rootFolderId)) {
                return null;
            }

            return ['scopeKey' => $scopeKey, 'rootFolderId' => $rootFolderId];
        } catch (HttpExceptionInterface) {
            return null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Root folder id to authorize against, or 0 when the scope has no root
     * folder yet — mount() creates it on the first real visit. An authorizer
     * that keys on the id rather than the scope key has to treat 0 as "not
     * created yet"; the shipped ones decide on the scope key.
     */
    private static function rootFolderId(FileExplorerRootResolver $resolver): int
    {
        if ($resolver instanceof ResolvesExistingRoot) {
            return $resolver->existingRootFolderId() ?? 0;
        }

        // A resolver that cannot answer without creating keeps the old
        // behaviour rather than being denied.
        return $resolver->rootFolderId();
    }
}
