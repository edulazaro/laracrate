<?php

namespace EduLazaro\Laracrate\Tests\Support;

use EduLazaro\Laracrate\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo dummy para tests del trait HasFiles.
 */
class HasFilesTestModel extends Model
{
    use HasFiles;

    protected $table = 'test_owners';
    protected $guarded = [];
    public $timestamps = true;
}
