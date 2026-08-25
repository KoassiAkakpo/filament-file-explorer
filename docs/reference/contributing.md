---
title: Contributing
description: Running the two test suites, and the conventions the codebase holds itself to.
---

# Contributing

```bash
composer install

./vendor/bin/pest                                    # the PHP suite
./vendor/bin/pest tests/Feature/StandalonePageTest.php
./vendor/bin/pest --filter="registers a navigation entry"

node --test "tests/Js/*.test.mjs"                    # selection and keyboard logic

./vendor/bin/pint                                    # format
./vendor/bin/pint --test                             # check only
```

Both suites run in CI on every push and pull request, on PHP 8.3 and 8.4.

> **8.3 is the floor for the test suite, not for the package.** The explorer runs on PHP 8.2, but Pest and Testbench both require 8.3 — so there is no toolchain to run the suite with below it.

## The PHP suite

Pest and Orchestra Testbench, `RefreshDatabase` on sqlite `:memory:`.

Two things about it that will cost you an afternoon if you meet them cold:

- **Provider order matters.** `Filament\Support\SupportServiceProvider` must register **before** `LivewireServiceProvider`: Support binds Livewire's `DataStore` non-shared, and Livewire re-binds it shared. Reversing them hands every component a fresh WeakMap and breaks Livewire's error bag on render. See `tests/TestCase.php`.
- **Compiled Blade views survive between runs**, in Testbench's own storage directory. Editing a template and re-running in the same second can serve the stale copy, which looks exactly like a change that did not take. Delete them if a view assertion disagrees with the file in front of you.

And one trap worth knowing before you write a test that measures bytes: `UploadedFile::fake()->create()` fakes `getSize()` but writes an **empty** file, so Media Library stores a size of 0. Use `createWithContent()` — with a real header, so the mime guess still passes the upload rules.

## The JavaScript suite

```bash
node --test "tests/Js/*.test.mjs"
```

**No dependencies and no build step.** `tests/Js/harness.mjs` runs the shipped `resources/js/file-explorer.js` in a `node:vm` context with just enough of Alpine and the DOM to drive the selection.

It exists because the keyboard and selection layer is where the bugs actually are, and no PHP test can reach it. Four things about it are load-bearing:

- **Objects cross the vm boundary**, so their prototype is not the test realm's. Compare them by value, never with `deepStrictEqual`.
- **`spawn()` puts a second explorer in the same realm**, sharing the Alpine stores. Two separate loads give two isolated realms instead, which proves nothing about anything shared — and "two explorers on one page" is exactly what the [picker](../integrating/picker.md) makes real.
- **`window.Alpine` is set**, so loading the script runs its bootstrap the way a browser does. Without that, a call left pointing at a renamed function threw on page load with every test green.
- **The stub models Alpine's reactivity.** Nested objects are handed out wrapped; the fake `$wire` throws when a method is invoked on a wrapper, the way the real Livewire proxy effectively breaks. That is not decoration — holding `$wire` as a property instead of in a closure shipped once with every test green, and it silently stopped the selection reaching the server.

## Conventions

- `declare(strict_types=1);` in every PHP file — enforced by `pint.json`.
- User-facing strings go through `__('filament-file-explorer::file-explorer.…')`, with entries in **both** `resources/lang/en` and `resources/lang/fr`.
- Non-obvious decisions are documented as short comments explaining **why**, not what the code does. The codebase is dense with them on purpose: most of them record a bug that shipped.
- Tailwind classes in Blade must be **literal**, never built from a variable — host applications scan the views.
- No `teal-` and no `rtl:` in the views. Tests assert both stay gone.
- Nothing calls `document.getElementById`. A test asserts that too.

`larastan`/`phpstan` are dev dependencies but there is **no `phpstan.neon`** — static analysis is not wired up.

Running `pint` across the whole repository reports pre-existing style drift in a few files. **Scope formatting to the files you touch** rather than reformatting the tree.

## The two rules a new entry point has to follow

If you add an action, a route, or anything else that reaches explorer files:

1. **Check the ability**, through `Support\Abilities` — and if it is a *new* ability, declare it in `Abilities::DERIVED` naming the one it inherits from. Never read `abilities()` raw. Nothing ever defaults to allowed.
2. **Prove containment**, through `Support\MediaScope` or `Support\FolderTree`, against a root that was **not** derived from the thing being checked.

[Authorization](../integrating/authorization.md#a-checklist-for-an-entry-point-of-your-own) has the full checklist.

## Adding a setting

A standalone setting goes in three places or it does not work — config, a fluent setter plus accessor on the plugin, and a reader on `Support\StandaloneSettings`. See [Configuration](configuration.md#adding-a-standalone-setting).

## These docs

They are Jekyll, built by GitHub Pages from the `docs/` directory on `main`. No build step and no `package.json` — the same stance the package takes for its own assets.

To add a page: write the markdown, and add it to `docs/_data/nav.yml`. That file is the **only** definition of the sidebar, and the prev/next links walk the same list, so there is nothing to keep in step.

To preview locally:

```bash
cd docs && bundle install && bundle exec jekyll serve
```

The `Gemfile` there exists for that alone — GitHub Pages builds the site with its own pinned gem set and ignores it.
