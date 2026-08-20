<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Resolvers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

/**
 * One root folder per authenticated user.
 *
 * The user key is part of the scope key too, so session state (current folder,
 * clipboard) and authorization never leak between users.
 */
class PerUserRootResolver implements FileExplorerRootResolver
{
    protected ?int $rootFolderId = null;

    public function scopeKey(): string
    {
        return StandaloneSettings::scopeKey().'.'.$this->userKey();
    }

    public function rootFolderId(): int
    {
        $userKey = $this->userKey();

        return $this->rootFolderId ??= (int) app(FileExplorerManager::class)->ensureRoot(
            StandaloneSettings::rootSlug().'-'.Str::slug((string) $userKey),
            StandaloneSettings::rootName().' — '.$this->userLabel(),
        )->id;
    }

    protected function userKey(): int|string
    {
        $user = Auth::user();

        abort_unless($user !== null, 403);

        return $user->getAuthIdentifier();
    }

    protected function userLabel(): string
    {
        $user = Auth::user();

        foreach (['name', 'email'] as $attribute) {
            $value = $user?->getAttribute($attribute);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return (string) $this->userKey();
    }
}
