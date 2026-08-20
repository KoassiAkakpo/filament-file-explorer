<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Koassi\FilamentFileExplorer\Models\Concerns\HasFileExplorer;

class Project extends Model
{
    use HasFileExplorer;

    protected $guarded = [];
}
