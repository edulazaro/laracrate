<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rename `laracrate_file_contents` → `laracrate_file_chunks`.
 *
 * El nombre "contents" era engañoso: la tabla siempre fue 1 fila por chunk
 * (no por file), así que "chunks" describe mejor su semántica. Prepara el
 * terreno para el split en una tabla payload separada (file_chunk_data) que
 * permite drop limpio del search MySQL al pasar a Meili.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laracrate_file_contents') && ! Schema::hasTable('laracrate_file_chunks')) {
            Schema::rename('laracrate_file_contents', 'laracrate_file_chunks');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('laracrate_file_chunks') && ! Schema::hasTable('laracrate_file_contents')) {
            Schema::rename('laracrate_file_chunks', 'laracrate_file_contents');
        }
    }
};
