<?php

declare(strict_types=1);

use Koassi\FilamentFileExplorer\Contracts\FileExplorerRootResolver;
use Koassi\FilamentFileExplorer\Models\Folder;
use Koassi\FilamentFileExplorer\Tests\Fixtures\Project;
use Koassi\FilamentFileExplorer\Tests\Fixtures\ProjectExplorerPage;

it('still auto-creates a root folder for a record', function (): void {
    $project = Project::create(['name' => 'Apollo']);

    expect($project->folder_id)->not->toBeNull()
        ->and(Folder::query()->find($project->folder_id)->name)->toBe('Apollo');
});

it('still scopes a record page to the record', function (): void {
    $project = Project::create(['name' => 'Apollo']);

    $page = new ProjectExplorerPage;
    $page->mountForRecord($project);

    expect($page->fileExplorerScopeKey())->toBe('project.'.$project->getKey())
        ->and($page->rootFolderId)->toBe((int) $project->folder_id);
});

it('keeps record and standalone scopes on separate roots', function (): void {
    $project = Project::create(['name' => 'Apollo']);

    $standaloneRoot = app(FileExplorerRootResolver::class)->rootFolderId();

    expect($standaloneRoot)->not->toBe((int) $project->folder_id);
});
