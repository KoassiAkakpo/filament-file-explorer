<?php

declare(strict_types=1);

use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Tests\Fixtures\Project;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectExplorerPage;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectFilesPage;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectResource;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A record that has no root folder yet.
 *
 * HasFileExplorer hooks `creating`, so only records made after the trait was
 * added were given a root — and adding the explorer to a model that already has
 * rows is the ordinary way this feature gets adopted. Those pages used to
 * answer 404, which reads as "no such page" over a record that is plainly
 * there, and left every host writing the same one-line override.
 */
beforeEach(function (): void {
    ProjectResource::$registeredPages = [];
});

/** A row as it exists in a table the trait was added to afterwards. */
function feRecordWithoutRoot(string $name = 'Apollo'): Project
{
    config()->set('filament-file-explorer.auto_create_root', false);
    $project = Project::create(['name' => $name]);
    config()->set('filament-file-explorer.auto_create_root', true);

    expect($project->folder_id)->toBeNull();

    return $project;
}

it('creates the root on the first visit to the explorer page', function (): void {
    $project = feRecordWithoutRoot();
    $before = Folder::query()->count();

    $page = new ProjectExplorerPage;
    $page->mountForRecord($project);

    expect($page->rootFolderId)->toBeGreaterThan(0)
        ->and(Folder::query()->count())->toBe($before + 1)
        ->and((int) $project->fresh()->folder_id)->toBe($page->rootFolderId);
});

it('creates the root on the first visit to the files page too', function (): void {
    $project = feRecordWithoutRoot();

    $page = new ProjectFilesPage;
    $page->mountForRecord($project);

    expect((int) $project->fresh()->folder_id)->toBe($page->rootFolderId);
});

it('names the root after the record', function (): void {
    $project = feRecordWithoutRoot('Apollo');

    $page = new ProjectExplorerPage;
    $page->mountForRecord($project);

    expect(Folder::query()->find($page->rootFolderId)->name)->toBe('Apollo');
});

it('makes one root, not one per visit', function (): void {
    $project = feRecordWithoutRoot();

    $first = new ProjectExplorerPage;
    $first->mountForRecord($project);

    $count = Folder::query()->count();

    $second = new ProjectExplorerPage;
    $second->mountForRecord($project->fresh());

    expect(Folder::query()->count())->toBe($count)
        ->and($second->rootFolderId)->toBe($first->rootFolderId);
});

it('keeps two records on roots of their own', function (): void {
    $one = feRecordWithoutRoot('Apollo');
    $two = feRecordWithoutRoot('Apollo');

    $pageOne = new ProjectExplorerPage;
    $pageOne->mountForRecord($one);

    $pageTwo = new ProjectExplorerPage;
    $pageTwo->mountForRecord($two);

    // Same name, so the same slug — which is allowed, since the unique index is
    // on (parent_id, slug) and no database compares NULL parents. Two records
    // sharing a name must not share a library.
    expect($pageTwo->rootFolderId)->not->toBe($pageOne->rootFolderId);
});

it('leaves a record that already has a root alone', function (): void {
    $project = Project::create(['name' => 'Apollo']);
    $existing = (int) $project->folder_id;
    $count = Folder::query()->count();

    $page = new ProjectExplorerPage;
    $page->mountForRecord($project);

    expect($page->rootFolderId)->toBe($existing)
        ->and(Folder::query()->count())->toBe($count);
});

it('refuses to write a root when auto-creation is off, and says why', function (): void {
    $project = feRecordWithoutRoot();
    config()->set('filament-file-explorer.auto_create_root', false);

    $count = Folder::query()->count();
    $raised = null;

    try {
        (new ProjectExplorerPage)->mountForRecord($project);
    } catch (HttpException $e) {
        $raised = $e;
    }

    // The host turned auto-creation off, so the roots are theirs to assign.
    // What changes is that the refusal names the cause and the fix instead of
    // being a bare 404 over a record that exists.
    expect($raised)->not->toBeNull()
        ->and($raised->getStatusCode())->toBe(404)
        ->and($raised->getMessage())->toContain('auto_create_root')
        ->and($raised->getMessage())->toContain('folder_id')
        ->and(Folder::query()->count())->toBe($count);
});
