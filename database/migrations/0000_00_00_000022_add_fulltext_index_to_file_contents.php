<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FULLTEXT index sobre `laracrate_file_contents.text` para soportar
 * `MATCH(text) AGAINST(? IN NATURAL LANGUAGE MODE)` (keyword search clásica
 * sobre el contenido extraído). Para búsqueda semántica vectorial, ver
 * `embedding` (json) y resolver fuera (Meili/pgvector/etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Si ya existe (otra migration lo añadió antes), no hacer nada.
        $exists = DB::selectOne("
            SELECT COUNT(*) AS c
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'laracrate_file_contents'
              AND INDEX_NAME   = 'laracrate_file_contents_text_fulltext'
        ")->c > 0;

        if (!$exists) {
            Schema::table('laracrate_file_contents', function (Blueprint $table) {
                $table->fullText('text', 'laracrate_file_contents_text_fulltext');
            });
        }
    }

    public function down(): void
    {
        $exists = DB::selectOne("
            SELECT COUNT(*) AS c
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'laracrate_file_contents'
              AND INDEX_NAME   = 'laracrate_file_contents_text_fulltext'
        ")->c > 0;

        if ($exists) {
            DB::statement('ALTER TABLE laracrate_file_contents DROP INDEX laracrate_file_contents_text_fulltext');
        }
    }
};
