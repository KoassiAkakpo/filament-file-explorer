<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One word in a scope's vocabulary.
 *
 * A tag is a row rather than a string on the item because the point of a tag is
 * that several items carry the same one: a shared row is what lets the filter
 * menu offer a closed list, an autocomplete propose what already exists, and a
 * colour mean the same thing everywhere it appears. Free-text strings per item
 * would give three spellings of "Urgent" within a week.
 *
 * Scoped, like everything else the package keeps per scope — a tenant's words
 * are not another tenant's.
 *
 * @property string $scope_key
 * @property string $name
 * @property string $slug
 * @property string|null $color
 */
class Tag extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-file-explorer.annotations.tags_table', 'file_explorer_tags');
    }
}
