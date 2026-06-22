<?php

namespace EduLazaro\Laracrate\Tests\Support;

use EduLazaro\Laracrate\Concerns\HasFiles;
use EduLazaro\Laracrate\Concerns\HasFolders;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo dummy para tests de los traits HasFiles y HasFolders.
 */
class HasFilesTestModel extends Model
{
    use HasFiles;
    use HasFolders;

    protected $table = 'test_owners';
    protected $guarded = [];
    public $timestamps = true;
}
