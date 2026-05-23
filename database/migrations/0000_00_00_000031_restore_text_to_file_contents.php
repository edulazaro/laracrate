<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaura la columna `text` y su FULLTEXT index en `laracrate_file_contents`.
 *
 * Razón: apps que usan FULLTEXT keyword search vía MySQL (alternativa a
 * Meilisearch) necesitan el texto del chunk en BD para hacer
 * `MATCH(text) AGAINST(?)`. Los sidecars en storage (`.json` y
 * `.chunks.jsonl`) siguen existiendo como backup portable.
 *
 * Esta migración deshace 0000_00_00_000030 cuando el caso de uso lo requiere.
 * Apps que NO usen FULLTEXT y prefieran ahorrar espacio pueden dropar la
 * columna manualmente o crear su propia migración de drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-añadir columna text (si no existe).
        if (! Schema::hasColumn('laracrate_file_contents', 'text')) {
            Schema::table('laracrate_file_contents', function (Blueprint $table) {
                $table->longText('text')->nullable()->after('chunk_index');
            });
        }

        // Re-añadir FULLTEXT index (si no existe).
        $exists = (int) DB::selectOne("
            SELECT COUNT(*) AS c
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'laracrate_file_contents'
              AND INDEX_NAME   = 'laracrate_file_contents_text_fulltext'
        ")->c;

        if ($exists === 0) {
            Schema::table('laracrate_file_contents', function (Blueprint $table) {
                $table->fullText('text', 'laracrate_file_contents_text_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::table('laracrate_file_contents', function (Blueprint $table) {
            if (Schema::hasColumn('laracrate_file_contents', 'text')) {
                $table->dropColumn('text');
            }
        });
    }
};
