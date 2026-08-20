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

        // A scope may offer more than one root, and a media link made under one
        // of them stays valid after the user switches to another, so the entry
        // is a set rather than a single id.
        $ids = $roots[$scopeKey] ?? [];
        $ids = array_values(array_unique([...$ids, $rootFolderId]));

        // Re-inserting moves the key to the end, which makes the eviction below
        // least-recently-used rather than arbitrary.
        unset($roots[$scopeKey]);
        $roots[$scopeKey] = $ids;

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
        return self::resolveAll($scopeKey)[0] ?? null;
    }

    /**
     * Every root the current request may browse under $scopeKey. More than one
     * only when the resolver offers a multi-root scope; they share a scope key,
     * and therefore one ability set.
     *
     * @return list<int>
     */
    public static function resolveAll(string $scopeKey): array
    {
        $roots = self::fromSession($scopeKey);

        return $roots !== [] ? $roots : self::fromStandaloneResolver($scopeKey);
    }

    /**
     * @return list<int>
     */
    private static function fromSession(string $scopeKey): array
    {
        return self::all()[$scopeKey] ?? [];
    }

    /**
     * Standalone scopes stay resolvable without any session state, which keeps
     * media links working in a fresh session (a preview opened from a mail, a
     * new browser) for the one scope the resolver says belongs to this request.
     */
    /**
     * @return list<int>
     */
    private static function fromStandaloneResolver(string $scopeKey): array
    {
        try {
            $resolver = app(FileExplorerRootResolver::class);

            if ($resolver->scopeKey() !== $scopeKey) {
                return [];
            }

            // Every root the scope offers, so a media link keeps working in a
            // fresh session whichever root it was made under.
            $ids = ActiveRoot::ids($scopeKey);

            if ($ids !== []) {
                return $ids;
            }

            // Read-only: a request for a media file must never create a root
            // folder, and if the scope has none there is no media to serve.
            $rootFolderId = $resolver instanceof ResolvesExistingRoot
                ? (int) $resolver->existingRootFolderId()
                : $resolver->rootFolderId();

            return $rootFolderId > 0 ? [$rootFolderId] : [];
        } catch (\Throwable) {
            // Per-user and per-tenant resolvers abort without a user or tenant,
            // and the plugin is unavailable outside a panel: either way this is
            // simply not a standalone scope for this request.
            return [];
        }
    }

    /**
     * @return array<string, list<int>>
     */
    private static function all(): array
    {
        if (! self::hasSession()) {
            return [];
        }

        $roots = session(self::sessionKey(), []);

        if (! is_array($roots)) {
            return [];
        }

        $normalised = [];

        foreach ($roots as $scopeKey => $ids) {
            // Sessions written before a scope could hold several roots stored a
            // bare id.
            $ids = array_values(array_filter(
                array_map('intval', is_array($ids) ? $ids : [$ids]),
                fn (int $id): bool => $id > 0,
            ));

            if ($ids !== []) {
                $normalised[(string) $scopeKey] = $ids;
            }
        }

        return $normalised;
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
        // Bound rather than started: the session helper answers as soon as the
        // manager exists, and request()->hasSession() is false in contexts —
        // tests, queued work — where reading and writing still work.
        return app()->bound('session');
    }
}
