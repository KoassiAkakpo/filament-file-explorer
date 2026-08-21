<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Koassi\FilamentFileExplorer\Contracts\FileExplorerAuthorizer;

/**
 * Resolves what a scope may do, and makes the set able to grow.
 *
 * FileExplorerAuthorizer::abilities() returns a fixed array. Reading a key the
 * package invented after the contract was published would find nothing in every
 * authorizer a host app has already written, and deny the action for all of
 * them without a word — which is why the trash's restore and purge had to reuse
 * `delete` and `deleteFolder` rather than ask for abilities of their own. Left
 * alone, that constraint becomes permanent at 1.0.
 *
 * So a new ability declares the one it is a variation of, and inherits its
 * answer when the authorizer says nothing. An author who denied `download`
 * meant to deny sharing too; an authorizer that does answer the new key wins
 * over the inheritance. Nothing new ever defaults to allowed.
 *
 * It also memoises. `ability()` is read dozens of times per render, and an
 * authorizer that consults policies or the database was being asked every
 * single time.
 */
final class Abilities
{
    /**
     * The set the published contract names — the only keys an authorizer
     * written against it can be expected to answer.
     */
    public const ORIGINAL = [
        'browse',
        'search',
        'getInfo',
        'download',
        'upload',
        'mkdir',
        'rename',
        'move',
        'copy',
        'delete',
        'deleteFolder',
    ];

    /**
     * Abilities added since, each naming the one it inherits from.
     *
     * Declaration order is resolution order, so an ability may inherit from
     * another derived one as long as it is declared after it.
     *
     * `share` is declared ahead of the feature that will read it: the point of
     * this class is that the declaration has to exist before the key is used,
     * not after.
     *
     * @var array<string, string>
     */
    public const DERIVED = [
        'share' => 'download',
    ];

    /** @var array<string, array<string, bool>> */
    private array $resolved = [];

    /**
     * @return array<string, bool>
     */
    public function all(string $scopeKey, int $rootFolderId): array
    {
        return $this->resolved[$scopeKey.':'.$rootFolderId] ??= $this->resolve($scopeKey, $rootFolderId);
    }

    public function allows(string $scopeKey, int $rootFolderId, string $ability): bool
    {
        return $this->all($scopeKey, $rootFolderId)[$ability] ?? false;
    }

    public function flush(): void
    {
        $this->resolved = [];
    }

    /**
     * @return array<string, bool>
     */
    private function resolve(string $scopeKey, int $rootFolderId): array
    {
        $answered = app(FileExplorerAuthorizer::class)->abilities($scopeKey, $rootFolderId);

        $map = [];

        foreach (self::ORIGINAL as $ability) {
            $map[$ability] = (bool) ($answered[$ability] ?? false);
        }

        foreach (self::DERIVED as $ability => $inheritsFrom) {
            $map[$ability] = array_key_exists($ability, $answered)
                ? (bool) $answered[$ability]
                : ($map[$inheritsFrom] ?? false);
        }

        // An authorizer is free to volunteer keys of its own — a host app may
        // read them for its own actions, and dropping them here would make this
        // class narrower than the one it replaced.
        foreach ($answered as $ability => $allowed) {
            if (! array_key_exists($ability, $map)) {
                $map[$ability] = (bool) $allowed;
            }
        }

        return $map;
    }
}
