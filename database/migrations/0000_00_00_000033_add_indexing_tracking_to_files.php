<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking de dónde están indexados los chunks de cada file.
 *
 * - `mysql_indexed_at`: chunks pobladas en `laracrate_file_chunk_data` (text +
 *    embedding listos para FULLTEXT MySQL + cosine PHP).
 * - `meili_indexed_at`: chunks pushed a Meilisearch.
 * - `storage_indexed_at`: `{path}.chunks.jsonl` backup escrito.
 *
 * Permite re-index incremental ("solo lo desactualizado"), health checks
 * y migración segura entre backends (no dropear MySQL hasta confirmar que
 * Meili está poblado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->timestamp('mysql_indexed_at')->nullable()->after('processing_started_at')
                ->index();
            $table->timestamp('meili_indexed_at')->nullable()->after('mysql_indexed_at')
                ->index();
            $table->timestamp('storage_indexed_at')->nullable()->after('meili_indexed_at');
        });
    }

    public function down(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->dropColumn(['mysql_indexed_at', 'meili_indexed_at', 'storage_indexed_at']);
        });
    }
};
