---
title: Choosing the root folder
description: One shared library, one per user, one per tenant, or your own — and the two optional interfaces worth implementing.
---

# Choosing the root folder

A resolver answers two questions: what is the **scope key** (the authorization and session namespace) and what is the **root folder id**. Everything else in the package is built on that pair.

## The three that ship

| Resolver | Behaviour |
| --- | --- |
| `GlobalRootResolver` *(default)* | One root folder shared by everyone |
| `PerUserRootResolver` | One root folder per authenticated user |
| `PerTenantRootResolver` | One root folder per Filament tenant — reusing the tenant's own root when it uses `HasFileExplorer` |

```php
use Koassi\FilamentFileExplorer\Resolvers\PerUserRootResolver;

FilamentFileExplorerPlugin::make()->rootResolver(PerUserRootResolver::class)
```

The per-user and per-tenant resolvers put the user or tenant key **into the scope key**, which is what keeps their session state apart: the current folder, the clipboard and the view preference are all namespaced by it, as are tags.

## Your own

```php
use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;

class TeamRootResolver implements FileExplorerRootResolver
{
    public function scopeKey(): string
    {
        return 'team.'.auth()->user()->current_team_id;
    }

    public function rootFolderId(): int
    {
        return auth()->user()->currentTeam->ensureFileExplorerRoot()->id;
    }
}
```

Bind it as a **singleton**, so the root is resolved once per request rather than on every read.

Anything a resolver throws that is not an abort is reported rather than swallowed. A missing migration or a mistyped class would otherwise show up as a page quietly absent from the menu, which is the hardest kind of failure to chase.

## Never create a root while authorizing

Filament calls `canAccess()` on **every navigation render**. A resolver that creates the root folder when asked for it therefore writes a folder every time a menu is drawn — for every authenticated user, including the ones the check is about to deny.

`ResolvesExistingRoot` is the read-only escape hatch:

```php
use Koassi\FilamentFileExplorer\Contracts\ResolvesExistingRoot;

class TeamRootResolver implements FileExplorerRootResolver, ResolvesExistingRoot
{
    // Must answer without writing. Null means "this scope has no root yet".
    public function existingRootFolderId(): ?int
    {
        return auth()->user()?->currentTeam?->folder_id;
    }

    // ...
}
```

All three shipped resolvers implement it. When the scope has no root yet the authorizer is asked with `0`, and the folder is created on the first real visit — not on the render of a menu.

It is a **separate interface on purpose**: adding a method to `FileExplorerRootResolver` would break every resolver already written against it.

## Several roots for one scope

`ProvidesMultipleRoots` turns the sidebar into a location switcher:

```php
use Koassi\FilamentFileExplorer\Contracts\ProvidesMultipleRoots;

class TeamRootResolver implements FileExplorerRootResolver, ProvidesMultipleRoots
{
    public function roots(): array
    {
        return [
            ['id' => auth()->user()->ensureFileExplorerRoot()->id, 'name' => 'My files'],
            ['id' => $this->sharedRootId(), 'name' => 'Shared'],
        ];
    }

    // ...
}
```

Three things follow, and they are the ones to know before reaching for it:

- **The roots share the scope key, and therefore share one authorization decision and one ability set.** Roots that must be authorized apart belong in separate scopes, not in this list.
- **The explorer still browses exactly one root at a time.** Every action compares containment against a single root id, which is what makes the check worth anything. The current folder and the clipboard are namespaced per root, so each location remembers where it was left and a copied file of one root means nothing in another.
- **A switch is validated against `roots()`**, never against an id from the request. Otherwise any folder in the tree could be passed off as a root, and containment against it would be trivially true.

## Ensuring a root

Use `FileExplorerManager::ensureRoot()` rather than creating the folder yourself. The folders table is unique on `(parent_id, slug)`, but MySQL does not dedupe rows where `parent_id` is null — so two concurrent first hits could each create a root. `ensureRoot()` closes that with a cache lock, and falls through to the unlocked path when the cache store cannot lock.

```php
use Koassi\FilamentFileExplorer\Support\FileExplorerManager;

$root = app(FileExplorerManager::class)->ensureRoot('team-files', 'Team files');
```

Models using [`HasFileExplorer`](record-scoped-pages.md) get `ensureFileExplorerRoot()`, which goes through the same path.
