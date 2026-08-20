<?php

declare(strict_types=1);

namespace Koassi\FilamentFileExplorer\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usually unsaved — the media routes only need an authenticated identity, and
 * the per-user resolvers only need its auth identifier — but the users table
 * exists so an uploader can be resolved back to a name.
 */
class User extends Authenticatable
{
    protected $guarded = [];

    public $timestamps = false;
}
