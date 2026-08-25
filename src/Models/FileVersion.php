<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One media row's place in a file's history.
 *
 * There is a line for the live row too, not only for the versions behind it:
 * without it the lineage of the file on screen would have nowhere to be read
 * from, and the history could only be found by walking backwards from a row
 * that no longer exists.
 *
 * @property string $lineage
 * @property int $media_id
 * @property int $sequence
 * @property Carbon|null $replaced_at
 */
class FileVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'media_id' => 'integer',
        'sequence' => 'integer',
        'replaced_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return (string) config('filament-file-explorer.versions.table', 'file_explorer_file_versions');
    }
}
