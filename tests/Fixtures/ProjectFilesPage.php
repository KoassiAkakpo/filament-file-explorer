<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Koassi\FilamentFileExplorer\Pages\FileExplorerFilesPage;

class ProjectFilesPage extends FileExplorerFilesPage
{
    protected static string $resource = ProjectResource::class;

    public function mountForRecord(Model $record): void
    {
        $this->record = $record;
        $this->mountFileExplorer();
    }

    /** getHeaderActions() is protected, and it is the thing under test. */
    public function headerActions(): array
    {
        return $this->getHeaderActions();
    }
}
