---
title: Authorization
description: Two independent guards on every entry point, and an ability set that can grow.
---

# Authorization

Two guards, both required, on every action and every route. Never add an entry point that skips either.

- **The ability check** — your authorizer answers what a scope may do.
- **The containment check** — every folder and media id is walked up the tree to prove it belongs to the root currently being browsed.

The package ships permissive (`AllowAllAuthorizer`), which is right for getting started and wrong for production.

## Writing an authorizer

```bash
php artisan filament-file-explorer:make-authorizer
```

```php
FilamentFileExplorerPlugin::make()->authorizer(\App\Support\FileExplorerAuthorizer::class)
```

The contract is four methods:

```php
interface FileExplorerAuthorizer
{
    public function canAccess(string $scopeKey, int $rootFolderId): bool;

    public function abilities(string $scopeKey, int $rootFolderId): array;

    public function mediaDeleteState(string $scopeKey, Media $media): array;

    public function folderDeleteState(string $scopeKey, Folder $folder): array;
}
```

`$scopeKey` tells you which explorer is being accessed: the configured scope key for the standalone page, `{model-kebab}.{id}` for a [record page](../getting-started/record-scoped-pages.md).

## The eleven abilities

```php
public function abilities(string $scopeKey, int $rootFolderId): array
{
    $canWrite = auth()->user()->can('manage-library');

    return [
        'browse'       => true,
        'search'       => true,
        'getInfo'      => true,
        'download'     => true,
        'upload'       => $canWrite,
        'mkdir'        => $canWrite,
        'rename'       => $canWrite,
        'move'         => $canWrite,
        'copy'         => $canWrite,
        'delete'       => $canWrite,
        'deleteFolder' => $canWrite,
    ];
}
```

**One left out is a denial.** Nothing ever defaults to allowed — an ability nobody declared is refused.

Your own extra keys are kept, so you can read them for actions of your own.

## An ability set that can grow

Nothing reads your array directly. `Support\Abilities` does, and an ability the explorer gains **later** that your implementation does not answer **inherits the one it is a variation of** rather than being read as a refusal:

| Added since | Follows | Because |
| --- | --- | --- |
| `share` | `download` | Sharing hands out the same bytes |
| `annotate` | `rename` | Both change what an item is called, not what it holds |
| `viewVersions` | `getInfo` | Reading a history is reading about the file |
| `restoreVersion` | `upload` | Making a version current decides what the folder holds |

So an authorizer written today keeps working and keeps meaning what you meant by it. Answer the new key yourself to have the last word:

```php
return [
    // … the eleven
    'share' => false,   // explicit: no sharing even though download is allowed
];
```

This is what `1.0.0` freezes and what makes the freeze survivable: **the key set can grow, but only downward from an ability that already existed.** Nothing new defaults to allowed, and nothing new turns an existing implementation into a silent refusal.

> The trash's restore and purge deliberately still take `delete` and `deleteFolder` rather than abilities of their own. They predate this mechanism, and changing them now would *widen* access for every authorizer written since.

## Delete windows

The two delete-state methods let a deletion be refused for reasons an ability cannot express — "not within ten minutes of upload", "not by someone who did not upload it":

```php
public function mediaDeleteState(string $scopeKey, Media $media): array
{
    $age = $media->created_at->diffInSeconds(now());
    $window = 600;

    return [
        'allowed'           => $age <= $window,
        'reason_code'       => 'window_elapsed',
        'reason'            => __('Files can only be deleted within ten minutes of upload.'),
        'remaining_seconds' => max(0, $window - $age),
        'window_seconds'    => $window,
    ];
}
```

The [confirmation dialog](../browsing/preview.md#deleting) reads this: refused items are **listed and kept**, and the rest of the selection still goes. A whole action failing because one file of forty is protected would be worse for everyone.

## Containment

Folder and media ids arrive as user input — Livewire parameters, query strings, route bindings, a share token. Every one of them is proved to sit under the root being browsed.

**The check is only worth anything if the root is not derived from the thing being checked.** Walking up to a media's top-most ancestor proves the file sits under its own ancestor, which is always true. So the media routes take the scope key from their own URL and resolve the root through the standalone resolver, or from the roots the current session has legitimately opened.

A media id needs three conditions cleared, and `Support\MediaScope` is the single implementation of them:

```php
use Koassi\FilamentFileExplorer\Support\MediaScope;

$folder = app(MediaScope::class)->folderUnderRoot($media, $rootFolderId);

if ($folder === null) {
    abort(403);
}
```

1. The row's `model_type` is the folder morph class.
2. Its `collection_name` is the explorer's collection.
3. Its folder sits under the root.

Drop either of the first two and a media row of some **other** model, whose `model_id` happens to collide with an in-scope folder id, passes containment. Resolve the folder and skip the check when the lookup returns null — which the component once did — and every media row of every other model becomes reachable for rename, move, copy, info and delete.

For folders:

```php
use Koassi\FilamentFileExplorer\Support\FolderTree;

app(FolderTree::class)->isUnderRoot($folder, $rootFolderId);
```

Bound `scoped`, because it memoises containment answers for one request. A shared instance in a long-lived worker would keep answering for a tree that has since been reorganised.

## The media routes

`show`, `zip-media` and `zip-folder`, plus the [share](../files/share-links.md) route.

They **never trust the scope key in their own URL**. They resolve the scope's root folder through the standalone resolver, or from the roots the current session has legitimately opened, and then require the media to sit under it. So a media link is only served to someone whose session has a claim to that scope — a URL kept from an expired session returns 403 until the explorer page is opened again.

The session registry maps a scope key to a **set** of roots rather than one, because a link made under one root of a [multi-root scope](../getting-started/root-resolvers.md#several-roots-for-one-scope) must keep working after the user switches. Every id in that set got there through a `canAccess()` that passed, and the bucket is keyed by the authenticated user — so widening the set never widens access.

## Session state

Namespaced by scope key, which is why the per-user and per-tenant resolvers put the user or tenant key in it:

| | |
| --- | --- |
| `currentFolderId.{scopeKey}.{rootId}` | Where you were |
| `clipboard.{scopeKey}.{rootId}` | Cut or copied items |
| `activeRoot.{scopeKey}` | Which root of a multi-root scope |

The current folder and the clipboard carry the **root id as well**, since one scope may offer several roots and a folder or a copied file of one root means nothing in another.

## A checklist for an entry point of your own

If you extend the component or add a route that reaches explorer files:

1. Check the ability, through `Support\Abilities` — never by reading `abilities()` yourself.
2. Prove containment, through `MediaScope` or `FolderTree` — never by walking up to an ancestor of the thing you are checking.
3. Get the root from the scope, not from the request.
4. Filter on the explorer's collection, so trashed files and kept versions stay out.
