<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidación de processing state a nivel file (no per-chunk):
 *  - status / error / provider / model / summary / extractor viven en `files`
 *    porque se re-escriben con cada extracción (el file es la unidad lógica
 *    del proceso, no cada chunk).
 *  - Las chunks recuperan `text` y `embedding` directos (revertimos el split
 *    a `laracrate_file_chunk_data` que era prematuro).
 *  - `laracrate_file_chunk_data` se borra.
 *
 * Cuando una app pase a Meilisearch, drop `laracrate_file_chunks` entera
 * (los chunks viven en Meili + JSONL). `files` queda con metadata + state.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Añadir columnas process-state a laracrate_files.
        Schema::table('laracrate_files', function (Blueprint $table) {
            if (! Schema::hasColumn('laracrate_files', 'processing_extractor')) {
                $table->string('processing_extractor', 255)->nullable()->after('processing_error');
            }
            if (! Schema::hasColumn('laracrate_files', 'processing_provider')) {
                $table->string('processing_provider', 50)->nullable()->after('processing_extractor');
            }
            if (! Schema::hasColumn('laracrate_files', 'processing_model')) {
                $table->string('processing_model', 100)->nullable()->after('processing_provider');
            }
            if (! Schema::hasColumn('laracrate_files', 'summary')) {
                $table->text('summary')->nullable()->after('processing_model');
            }
        });

        // 2. Devolver text + embedding a laracrate_file_chunks desde chunk_data.
        Schema::table('laracrate_file_chunks', function (Blueprint $table) {
            if (! Schema::hasColumn('laracrate_file_chunks', 'text')) {
                $table->longText('text')->nullable()->after('chunk_index');
            }
            if (! Schema::hasColumn('laracrate_file_chunks', 'embedding')) {
                $table->json('embedding')->nullable()->after('text');
            }
        });

        if (Schema::hasTable('laracrate_file_chunk_data')) {
            DB::statement("
                UPDATE laracrate_file_chunks fc
                JOIN laracrate_file_chunk_data fcd ON fcd.file_chunk_id = fc.id
                SET fc.text = fcd.text, fc.embedding = fcd.embedding
            ");
        }

        // 3. Restaurar FULLTEXT index sobre laracrate_file_chunks.text.
        $hasIndex = (int) DB::selectOne("
            SELECT COUNT(*) AS c FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'laracrate_file_chunks'
              AND INDEX_NAME   = 'laracrate_file_chunks_text_fulltext'
        ")->c;

        if ($hasIndex === 0) {
            Schema::table('laracrate_file_chunks', function (Blueprint $table) {
                $table->fullText('text', 'laracrate_file_chunks_text_fulltext');
            });
        }

        // 4. Migrar campos chunk → file (se queda el último chunk como ref).
        //    Solo aplica si existían las columnas previas en chunks.
        $hadStatus = Schema::hasColumn('laracrate_file_chunks', 'status');
        if ($hadStatus) {
            DB::statement("
                UPDATE laracrate_files f
                LEFT JOIN (
                    SELECT file_id,
                           MAX(status)   AS status,
                           MAX(error)    AS error,
                           MAX(provider) AS provider,
                           MAX(model)    AS model,
                           MAX(summary)  AS summary
                    FROM laracrate_file_chunks
                    GROUP BY file_id
                ) c ON c.file_id = f.id
                SET
                    f.processing_status   = COALESCE(f.processing_status, c.status),
                    f.processing_error    = COALESCE(f.processing_error, c.error),
                    f.processing_provider = COALESCE(f.processing_provider, c.provider),
                    f.processing_model    = COALESCE(f.processing_model, c.model),
                    f.summary             = COALESCE(f.summary, c.summary)
            ");
        }

        // 5. Drop columnas redundantes de file_chunks.
        Schema::table('laracrate_file_chunks', function (Blueprint $table) {
            foreach (['summary', 'description', 'provider', 'model', 'status', 'error'] as $col) {
                if (Schema::hasColumn('laracrate_file_chunks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // 6. Drop tabla chunk_data (ya no se usa).
        Schema::dropIfExists('laracrate_file_chunk_data');
    }

    public function down(): void
    {
        // Recrear chunk_data table.
        if (! Schema::hasTable('laracrate_file_chunk_data')) {
            Schema::create('laracrate_file_chunk_data', function (Blueprint $table) {
                $table->id();
                $table->foreignId('file_chunk_id')
                    ->constrained('laracrate_file_chunks')
                    ->cascadeOnDelete();
                $table->longText('text')->nullable();
                $table->json('embedding')->nullable();
                $table->timestamps();
                $table->unique('file_chunk_id');
                $table->fullText('text', 'laracrate_file_chunk_data_text_fulltext');
            });

            DB::statement("
                INSERT INTO laracrate_file_chunk_data (file_chunk_id, text, embedding, created_at, updated_at)
                SELECT id, text, embedding, NOW(), NOW()
                FROM laracrate_file_chunks
            ");
        }

        Schema::table('laracrate_file_chunks', function (Blueprint $table) {
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->text('summary')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('error')->nullable();

            if (Schema::hasColumn('laracrate_file_chunks', 'text')) {
                $table->dropColumn('text');
            }
            if (Schema::hasColumn('laracrate_file_chunks', 'embedding')) {
                $table->dropColumn('embedding');
            }
        });

        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->dropColumn(['processing_extractor', 'processing_provider', 'processing_model', 'summary']);
        });
    }
};
