---
title: Share links
description: A public link to one file — expiring, revocable, and dying on its own when the file moves.
---

# Share links

Right-click a file and **Share** gives it a link that works with no account and no session — which is the point: it is how you send a file to someone outside the panel. The dialog shows the link, when it expires, how many times it has been opened, and a button to stop sharing.

```php
// config/filament-file-explorer.php
'share' => [
    'enabled' => true,
    'table' => 'file_explorer_shares',
    'default_ttl_days' => 7,
    'max_ttl_days' => 30,   // a ceiling, not a default; null allows links that never expire
],
```

`max_ttl_days` is a **ceiling**: a caller asking for longer, or for no expiry, gets the ceiling. Set it to `null` to allow links that never expire, and `default_ttl_days` to `null` to make that the default.

Gated on the `share` ability, which [follows `download`](../integrating/authorization.md#an-ability-set-that-can-grow) for any authorizer that does not answer it — so an authorizer written before this existed keeps meaning what its author meant, and one that refuses downloads refuses sharing too.

![The share dialog: the link, when it expires, how often it has been opened, and a way to stop](https://koassiakakpo.github.io/filament-file-explorer/assets/screenshots/share.webp)

## How it holds up without a session

This is the only route in the package with no authenticated user behind it, so the two guards cannot both run at request time. They are **split in time**:

- **The ability is checked when the link is made**, and that decision is what the row records. There is nobody to ask afterwards.
- **Containment is checked on every request**, against the root folder stored on the row — never against anything derived from the media, which would only prove the file sits under its own ancestor.

That split is what makes a link **die on its own**, with nothing to keep in step:

| The file is | The link |
| --- | --- |
| moved out of the scope it was shared from | fails the containment walk |
| [trashed](trash.md) | leaves the explorer's collection and fails the same check |
| deleted | has no row |

Nothing keeps a share in step with the tree, because nothing has to.

## The route

It takes the **token and nothing else** — no scope key, no media id, no conversion name — so a link cannot be edited into a link for another file or another rendition.

A bad token, an expired one, a revoked one and a file that has gone all answer **404 alike**. Telling them apart would say whether a token was ever real.

Middleware is `['web']`, deliberately without `auth`: a link that needed an account would not be a share.

## Revoking

Links are **stored rather than signed**, because revoking is the half of sharing that matters. A signed link cannot be withdrawn without a revocation list, which is a stored row with extra steps.

Revoking is a database write and takes effect immediately. The row is **kept**, so a withdrawn share is still there to inspect.

Sharing the same file twice hands back the existing link rather than minting a second, so revoking has one thing to revoke — and `FileShared` therefore fires only for a link that is actually new.

## Events

| | |
| --- | --- |
| `FileShared` | `media`, `share` — only for a link that did not already exist |
| `ShareRevoked` | `share` |

## Reading it yourself

```php
use Koassi\FilamentFileExplorer\Support\Sharing;

$sharing = app(Sharing::class);

$share = $sharing->resolve($token);              // null for a bad, expired or revoked token
$media = $share ? $sharing->mediaFor($share) : null;   // null when containment fails

$sharing->activeForMedia($media->id, $scopeKey, $rootFolderId);
$sharing->url($share);
$sharing->revoke($share);
```

`resolve()` is the token half and `mediaFor()` the containment half — the same two calls the controller makes, so a link you validate in your own code and a link the route serves cannot disagree.
