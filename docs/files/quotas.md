---
title: Quotas
description: A cap per scope, refused before anything is written.
---

# Quotas

```php
// config/filament-file-explorer.php
'quota' => [
    'bytes' => 10 * 1024 ** 3,      // 10 GiB, or null for no limit
],
```

```php
FilamentFileExplorerPlugin::make()->quota(10 * 1024 ** 3)
```

## The cap is per root folder

Not per application. With the [per-user or per-tenant resolver](../getting-started/root-resolvers.md) each scope gets an allowance **of that size** — which is the only reading of a quota that means anything when the resolver hands out one root each.

The sidebar shows the usage as soon as a limit is set: amber past 85%, red when full. The [dashboard widget](../getting-started/standalone-page.md#the-dashboard-widget) shows the same figures somewhere a person sees them without going to the explorer first.

## What counts

Everything the scope's bytes are responsible for, whole subtree:

- live files,
- [trashed](trash.md) files,
- [kept versions](versions.md).

The last two count because they occupy the disk until something purges them. A trash that stopped counting would be a way over the cap — delete, re-upload, repeat — and so would a version store.

## Where it is enforced

At the two places bytes appear: an upload, and a copy. Both refuse **before anything is written**, and report the reason.

Two details worth knowing:

- **It reserves within the request** rather than re-reading the sum. An upload adds rows one at a time, so measuring every file of a ten-file upload against the same stale total would let them all through.
- **A refusal is counted and reported once per action**, not once per file. Dropping thirty files into a scope with room for twenty gives one message.

A copy that does not fit is refused and says so, rather than partially copying a folder.

## Reading it yourself

```php
use Koassi\FilamentFileExplorer\Support\Quota;

$quota = app(Quota::class);

$quota->usedBytes($rootFolderId);
$quota->fileCount($rootFolderId);
$quota->fits($rootFolderId, $incomingBytes);
$quota->state($rootFolderId);        // null with no cap; otherwise the bar's own figures
```

`state()` is what the sidebar and the widget both draw from, so a screen of your own reporting a quota will agree with them rather than recomputing the thresholds.

Bound `scoped`: it memoises how many bytes a scope holds for the length of one request, and a shared instance in a long-lived worker would keep answering for a library that has since changed.

## Record-scoped quotas

The config value applies to whatever scope is being browsed, so a record-scoped page gets the same allowance per record. There is no per-record override in config — read `Quota` directly and enforce your own rule in an authorizer if a project needs a different one from a client.
