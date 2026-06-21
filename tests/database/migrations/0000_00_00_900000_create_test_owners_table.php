<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only table backing EduLazaro\Laracrate\Tests\Support\HasFilesTestModel
 * (morph alias `test_owner`). Lives in the test migration path, not the
 * package, so it is created and dropped by RefreshDatabase like any migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_owners');
    }
};
