<?php

namespace EduLazaro\Laracrate\Tests\Support;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed in user for the upload tests: grants are bound to the user that asked
 * for them, and the File creator must be a model. Reuses the test_owners table.
 */
class TestUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'test_owners';
    protected $guarded = [];
}
