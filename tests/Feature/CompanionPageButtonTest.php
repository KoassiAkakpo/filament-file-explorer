<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Koassi\FilamentFileExplorer\Tests\Fixtures\Project;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectExplorerPage;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectFilesPage;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectResource;

/**
 * The header button that links a record page to its companion.
 *
 * `make-page --explorer` and `make-page --list` each generate one page of the
 * pair, which is a documented way to use the generator — so the companion page
 * being absent is an ordinary configuration and not an edge case. It used to be
 * fatal: the URL was built inside the action's `url()` closure, which Filament
 * evaluates while rendering the header, long past the try/catch that was meant
 * to cover it. The page answered 500 with RouteNotFoundException from the
 * middle of a Blade view.
 */
beforeEach(function (): void {
    ProjectResource::$registeredPages = [];
});

afterEach(function (): void {
    ProjectResource::$registeredPages = [];
});

it('offers no files button when the resource has no files page', function (): void {
    ProjectResource::$registeredPages = ['files' => 'x'];

    $page = new ProjectExplorerPage;
    $page->mountForRecord(Project::create(['name' => 'Apollo']));

    expect($page->headerActions())->toBe([]);
});

it('offers no explorer button when the resource has no explorer page', function (): void {
    ProjectResource::$registeredPages = ['files-list' => 'x'];

    $page = new ProjectFilesPage;
    $page->mountForRecord(Project::create(['name' => 'Apollo']));

    expect($page->headerActions())->toBe([]);
});

it('never hands Filament an action whose url cannot be resolved', function (): void {
    // The property that was violated. Returning the action was never the
    // failure — resolving its url() was, and that happens while the header
    // renders. So the assertion has to reach the url, not the array.
    ProjectResource::$registeredPages = ['files' => 'x', 'files-list' => 'x'];

    $page = new ProjectExplorerPage;
    $page->mountForRecord(Project::create(['name' => 'Apollo']));

    $raised = null;

    foreach ($page->headerActions() as $action) {
        try {
            $action->getUrl();
        } catch (Throwable $e) {
            $raised = $e;
        }
    }

    // Caught by hand rather than with toThrow(Throwable::class): Throwable is an
    // interface, class_exists() is false for it, and Pest then reads the
    // argument as a message to match — so that expectation passes whatever
    // happens. It is the assertion this test had first, and it proved nothing.
    expect($raised)->toBeNull($raised === null ? '' : $raised->getMessage());
});

it('does not raise from the header when the page is registered but not routed', function (): void {
    // A page named in getPages() whose route does not exist — a panel with
    // register_pages off that wired up one of the pair by hand. The name check
    // passes and the URL still cannot be built, so the second layer answers.
    ProjectResource::$registeredPages = ['files' => 'x', 'files-list' => 'x'];

    $page = new ProjectExplorerPage;
    $page->mountForRecord(Project::create(['name' => 'Apollo']));

    expect($page->headerActions())->toBe([]);
});

/**
 * The other half of the report: `filament-file-explorer:install` printed
 *
 *   ERROR  Can't locate path: <…/src/../resources/dist>
 *
 * because `->hasAssets()` registers a publish group pointing at a directory
 * this package stopped having when the JS and CSS began shipping from their
 * sources. Laravel merges paths under one tag, so the images were published
 * anyway and the only symptom was an error nobody could act on.
 */
it('publishes no path that does not exist', function (string $tag): void {
    $paths = ServiceProvider::pathsToPublish(null, $tag);

    expect($paths)->not->toBeEmpty("nothing is registered under {$tag}");

    foreach (array_keys($paths) as $source) {
        expect(file_exists($source))->toBeTrue("{$tag} publishes a missing path: {$source}");
    }
})->with([
    'filament-file-explorer-assets',
    'filament-file-explorer-config',
    'filament-file-explorer-stubs',
    'filament-file-explorer-views',
]);
