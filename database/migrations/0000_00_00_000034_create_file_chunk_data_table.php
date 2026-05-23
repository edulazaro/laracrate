<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `laracrate_file_chunk_data`: payload pesado de chunks (text + embedding)
 * usado por el modo MySQL search (FULLTEXT `MATCH(text) AGAINST(?)` y
 * cosine PHP sobre embeddings).
 *
 * Separado de `laracrate_file_chunks` (registry) para que apps que pasen a
 * Meilisearch puedan dropar esta tabla de un golpe sin perder metadata,
 * status, ni audit log.
 *
 * Relación 1:1 con `laracrate_file_chunks` via `file_chunk_id`. Cascade
 * delete: borrar el chunk también borra su payload.
 *
 * Esta migración:
 *  1. Crea la tabla `laracrate_file_chunk_data`.
 *  2. Mueve datos existentes (text + embedding) desde `laracrate_file_chunks`.
 *  3. Dropea las columnas `text` y `embedding` de `laracrate_file_chunks`.
 *  4. Dropea el FULLTEXT index viejo (auto-dropped al dropear la columna).
 *  5. Crea el FULLTEXT index nuevo en `file_chunk_data.text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear nueva tabla.
        Schema::create('laracrate_file_chunk_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_chunk_id')
                ->constrained('laracrate_file_chunks')
                ->cascadeOnDelete();
            $table->longText('text')->nullable();
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->unique('file_chunk_id');
        });

        // 2. Migrar datos existentes (si existen).
        if (Schema::hasColumn('laracrate_file_chunks', 'text')
            || Schema::hasColumn('laracrate_file_chunks', 'embedding')) {

            DB::statement("
                INSERT INTO laracrate_file_chunk_data (file_chunk_id, text, embedding, created_at, updated_at)
                SELECT id, text, embedding, COALESCE(updated_at, NOW()), COALESCE(updated_at, NOW())
                FROM laracrate_file_chunks
                WHERE text IS NOT NULL OR embedding IS NOT NULL
            ");

            // 3. Dropear columnas de la tabla original (incluye FULLTEXT auto).
            Schema::table('laracrate_file_chunks', function (Blueprint $table) {
                if (Schema::hasColumn('laracrate_file_chunks', 'text')) {
                    $table->dropColumn('text');
                }
                if (Schema::hasColumn('laracrate_file_chunks', 'embedding')) {
                    $table->dropColumn('embedding');
                }
            });
        }

        // 5. Crear FULLTEXT index sobre la nueva columna text.
        Schema::table('laracrate_file_chunk_data', function (Blueprint $table) {
            $table->fullText('text', 'laracrate_file_chunk_data_text_fulltext');
        });
    }

    public function down(): void
    {
        // Restaurar columnas en laracrate_file_chunks + mover datos de vuelta.
        if (! Schema::hasColumn('laracrate_file_chunks', 'text')) {
            Schema::table('laracrate_file_chunks', function (Blueprint $table) {
                $table->longText('text')->nullable()->after('chunk_index');
                $table->json('embedding')->nullable()->after('text');
            });

            DB::statement("
                UPDATE laracrate_file_chunks fc
                JOIN laracrate_file_chunk_data fcd ON fcd.file_chunk_id = fc.id
                SET fc.text = fcd.text, fc.embedding = fcd.embedding
            ");

            Schema::table('laracrate_file_chunks', function (Blueprint $table) {
                $table->fullText('text', 'laracrate_file_chunks_text_fulltext');
            });
        }

        Schema::dropIfExists('laracrate_file_chunk_data');
    }
};
