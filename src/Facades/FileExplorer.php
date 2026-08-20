<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string collection()
 * @method static \Koassi\FilamentFileExplorer\Models\Folder createRoot(string $name, ?string $slug = null)
 * @method static \Koassi\FilamentFileExplorer\Models\Folder ensureRoot(string $slug, string $name)
 * @method static \Koassi\FilamentFileExplorer\Models\Folder|null findRoot(string $slug)
 * @method static \Koassi\FilamentFileExplorer\Models\Folder createChild(\Koassi\FilamentFileExplorer\Models\Folder $parent, string $name, ?string $slug = null)
 *
 * @see \Koassi\FilamentFileExplorer\Support\FileExplorerManager
 */
class FileExplorer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'filament-file-explorer';
    }
}
