<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an image is shown (2.0): `default`, `sphere` (a 360° photo) or `frames` (a strip
 * of a video's frames). Its type stays `image`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->string('mode', 20)->default('default')->after('type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->dropIndex(['mode']);
            $table->dropColumn('mode');
        });
    }
};
