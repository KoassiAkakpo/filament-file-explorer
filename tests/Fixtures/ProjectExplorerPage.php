<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Koassi\FilamentFileExplorer\Pages\FileExplorerPage;

class ProjectExplorerPage extends FileExplorerPage
{
    public function mountForRecord(Model $record): void
    {
        $this->record = $record;
        $this->mountFileExplorer();
    }
}
