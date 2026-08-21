<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Support;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Koassi\FilamentFileExplorer\Models\Folder;

/**
 * Which class the explorer builds its tree from.
 *
 * The model was hard-coded in eight files, so an application that needed
 * columns of its own, a global scope or a different table strategy had to fork.
 * Every comparable Filament and Spatie package lets the model be swapped, and
 * doing it later would change the type of everything the package hands back —
 * which is why it is a decision that had to be made before 1.0.
 *
 * Config only, not a plugin setting: a model cannot know which panel it is
 * being browsed from, the same reason the thumbnail conversion is config only.
 *
 * The replacement has to extend Folder. Nothing here would work otherwise — the
 * media collection, the conversions, the soft deletes and the containment walk
 * all live on it — and failing loudly at the first query beats a mystery three
 * layers down.
 */
final class FolderModel
{
    /**
     * Deliberately not memoised: a config array lookup and an is_a() cost
     * nothing next to the query that follows, and a cached class would outlive
     * a config change — which is every test that swaps the model.
     *
     * @return class-string<Folder>
     */
    public static function class(): string
    {
        $configured = config('filament-file-explorer.folders.model');

        if (! is_string($configured) || $configured === '') {
            return Folder::class;
        }

        if (! is_a($configured, Folder::class, true)) {
            throw new InvalidArgumentException(
                "[{$configured}] is configured as the file explorer folder model but does not extend ".Folder::class.'.'
            );
        }

        return $configured;
    }

    public static function new(): Folder
    {
        $class = self::class();

        return new $class;
    }

    /**
     * @return Builder<Folder>
     */
    public static function query(): Builder
    {
        return self::class()::query();
    }

    /**
     * What media rows store in model_type.
     *
     * Folder::getMorphClass() answers for the base class on purpose, so this
     * stays the same value after a swap and a library already uploaded is not
     * orphaned.
     */
    public static function morphClass(): string
    {
        return self::new()->getMorphClass();
    }
}
