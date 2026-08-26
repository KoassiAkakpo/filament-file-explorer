<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Filament\Resources\Resource;

/**
 * A resource whose registered pages a test can set.
 *
 * `make-page --explorer` and `make-page --list` each generate one page of the
 * pair, so "the companion page is not in getPages()" is one of the two
 * documented ways to use the generator rather than an exotic configuration.
 * This is what lets a test stand in either of them.
 */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    /**
     * What getPages() answers. Set per test; reset in the test's own setup.
     *
     * @var array<string, mixed>
     */
    public static array $registeredPages = [];

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return static::$registeredPages;
    }
}
