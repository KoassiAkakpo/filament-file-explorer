<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Resolvers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Koassi\FilamentFileExplorer\Contracts\ResolvesExistingRoot;
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;
use Koassi\FilamentFileExplorer\Support\StandaloneSettings;

/**
 * One root folder per authenticated user.
 *
 * The user key is part of the scope key too, so session state (current folder,
 * clipboard) and authorization never leak between users.
 */
class PerUserRootResolver implements ResolvesExistingRoot
{
    protected ?int $rootFolderId = null;

    public function scopeKey(): string
    {
        return StandaloneSettings::scopeKey().'.'.$this->userKey();
    }

    public function rootFolderId(): int
    {
        return $this->rootFolderId ??= (int) app(FileExplorerManager::class)->ensureRoot(
            $this->rootSlug(),
            StandaloneSettings::rootName().' — '.$this->userLabel(),
        )->id;
    }

    public function existingRootFolderId(): ?int
    {
        if ($this->rootFolderId !== null) {
            return $this->rootFolderId;
        }

        $id = app(FileExplorerManager::class)->findRoot($this->rootSlug())?->id;

        if ($id === null) {
            return null;
        }

        return $this->rootFolderId = (int) $id;
    }

    protected function rootSlug(): string
    {
        return StandaloneSettings::rootSlug().'-'.Str::slug((string) $this->userKey());
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
