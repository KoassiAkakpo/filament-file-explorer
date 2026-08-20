<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Support\Facades\Auth;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Contracts\ResolvesExistingRoot;

/**
 * Records which root folder a scope key resolved to, so the media routes can
 * look it up instead of trusting their own URL.
 *
 * Those routes take the scope key as a URL segment. Deriving the root from the
 * requested media — walking up to its top-most ancestor — makes the containment
 * check tautological: it proves the media belongs to its own tree, never that
 * the tree belongs to the caller. Pages and the Livewire component therefore
 * register the pair here once the authorizer has cleared it, while it is still
 * trustworthy, and the controller resolves the root from the registry.
 */
final class ScopeRoots
{
    /**
     * A session that browsed hundreds of record-scoped explorers would grow
     * unbounded, so the registry keeps only the most recent entries. Evicting
     * one costs a stale deep link a 403 until its page is opened again.
     */
    private const MAX_ENTRIES = 64;

    public static function remember(string $scopeKey, int $rootFolderId): void
    {
        if ($scopeKey === '' || $rootFolderId <= 0 || ! self::hasSession()) {
            return;
        }

        $roots = self::all();

        // Re-inserting moves the key to the end, which makes the eviction below
        // least-recently-used rather than arbitrary.
        unset($roots[$scopeKey]);
        $roots[$scopeKey] = $rootFolderId;

        if (count($roots) > self::MAX_ENTRIES) {
            $roots = array_slice($roots, -self::MAX_ENTRIES, null, true);
        }

        session([self::sessionKey() => $roots]);
    }

    /**
     * Root folder the current request is allowed to browse under $scopeKey, or
     * null when it has no claim to that scope.
     */
    public static function resolve(string $scopeKey): ?int
    {
        return self::fromSession($scopeKey) ?? self::fromStandaloneResolver($scopeKey);
    }

    private static function fromSession(string $scopeKey): ?int
    {
        $rootFolderId = (int) (self::all()[$scopeKey] ?? 0);

        return $rootFolderId > 0 ? $rootFolderId : null;
    }

    /**
     * Standalone scopes stay resolvable without any session state, which keeps
     * media links working in a fresh session (a preview opened from a mail, a
     * new browser) for the one scope the resolver says belongs to this request.
     */
    private static function fromStandaloneResolver(string $scopeKey): ?int
    {
        try {
            $resolver = app(FileExplorerRootResolver::class);

            if ($resolver->scopeKey() !== $scopeKey) {
                return null;
            }

            // Read-only: a request for a media file must never create a root
            // folder, and if the scope has none there is no media to serve.
            $rootFolderId = $resolver instanceof ResolvesExistingRoot
                ? (int) $resolver->existingRootFolderId()
                : $resolver->rootFolderId();

            return $rootFolderId > 0 ? $rootFolderId : null;
        } catch (\Throwable) {
            // Per-user and per-tenant resolvers abort without a user or tenant,
            // and the plugin is unavailable outside a panel: either way this is
            // simply not a standalone scope for this request.
            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private static function all(): array
    {
        if (! self::hasSession()) {
            return [];
        }

        $roots = session(self::sessionKey(), []);

        return is_array($roots) ? $roots : [];
    }

    /**
     * Bucketed per authenticated user: switching user without a full session
     * flush (impersonation) must not inherit the previous user's roots.
     */
    private static function sessionKey(): string
    {
        return 'filament-file-explorer.roots.'.(Auth::id() ?? 'guest');
    }

    private static function hasSession(): bool
    {
        return app()->bound('request') && request()->hasSession();
    }
}
