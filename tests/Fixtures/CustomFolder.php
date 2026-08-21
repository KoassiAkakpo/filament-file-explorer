<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * What a host app's own folder model looks like: the package's, plus whatever
 * it needs. The marker is here only so a test can prove the explorer really
 * built this class and not the base one.
 */
class CustomFolder extends Folder
{
    public function isTheHostAppsModel(): bool
    {
        return true;
    }
}
