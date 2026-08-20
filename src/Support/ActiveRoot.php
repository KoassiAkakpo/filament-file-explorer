<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Contracts\ProvidesMultipleRoots;

/**
 * Which of a scope's roots the user is currently in.
 *
 * Only ever one at a time: the containment check that guards every action
 * compares against a single root, and keeping it that way is what makes the
 * check worth anything. Switching root is therefore a deliberate act, checked
 * against the list the resolver offers — never against an id from the request.
 */
final class ActiveRoot
{
    /**
     * Roots the scope offers, or an empty list when it offers only the one.
     *
     * The resolver is asked only for the scope it owns, so a record-scoped
     * explorer — whose scope key is the record's — never gets a switcher.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function options(string $scopeKey): array
    {
        $resolver = self::resolverFor($scopeKey);

        if (! $resolver instanceof ProvidesMultipleRoots) {
            return [];
        }

        $roots = [];

        foreach ($resolver->roots() as $root) {
            $id = (int) ($root['id'] ?? 0);

            if ($id > 0) {
                $roots[] = ['id' => $id, 'name' => (string) ($root['name'] ?? '')];
            }
        }

        return count($roots) > 1 ? $roots : [];
    }

    /**
     * @return list<int>
     */
    public static function ids(string $scopeKey): array
    {
        return array_map(fn (array $root): int => $root['id'], self::options($scopeKey));
    }

    public static function offers(string $scopeKey, int $rootFolderId): bool
    {
        return in_array($rootFolderId, self::ids($scopeKey), true);
    }

    /**
     * The root a standalone page should open: the one the user last chose, if
     * it is still on offer, and otherwise whatever the resolver says.
     */
    public static function idFor(FileExplorerRootResolver $resolver): int
    {
        if (! $resolver instanceof ProvidesMultipleRoots) {
            return $resolver->rootFolderId();
        }

        $scopeKey = $resolver->scopeKey();
        $chosen = (int) (self::hasSession() ? session(self::sessionKey($scopeKey), 0) : 0);

        // Validated against the current list: a root the resolver has stopped
        // offering must not stay reachable through a stale session.
        return self::offers($scopeKey, $chosen) ? $chosen : $resolver->rootFolderId();
    }

    public static function choose(string $scopeKey, int $rootFolderId): void
    {
        if (self::hasSession()) {
            session([self::sessionKey($scopeKey) => $rootFolderId]);
        }
    }

    private static function resolverFor(string $scopeKey): ?FileExplorerRootResolver
    {
        try {
            $resolver = app(FileExplorerRootResolver::class);

            return $resolver->scopeKey() === $scopeKey ? $resolver : null;
        } catch (\Throwable) {
            // No user, no tenant, or no panel: not a standalone scope here.
            return null;
        }
    }

    private static function sessionKey(string $scopeKey): string
    {
        return 'activeRoot.'.$scopeKey;
    }

    private static function hasSession(): bool
    {
        // Bound rather than started: the session helper answers as soon as the
        // manager exists, and request()->hasSession() is false in contexts —
        // tests, queued work — where reading and writing still work.
        return app()->bound('session');
    }
}
