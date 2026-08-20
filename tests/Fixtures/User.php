<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Never persisted: the media routes only need an authenticated identity, and
 * the per-user resolvers only need its auth identifier.
 */
class User extends Authenticatable
{
    protected $guarded = [];

    public $timestamps = false;
}
