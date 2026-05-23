<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `text` column from `laracrate_file_contents`.
 *
 * El texto crudo de cada chunk vive ahora en el storage del file (disk
 * configurado: local/R2/S3) como sidecars:
 *   - {file.path}.text         → texto completo extraído (UTF-8 plano)
 *   - {file.path}.chunks.jsonl → {chunk_index, text, embedding, tokens} por línea
 *
 * MySQL conserva solo metadata + embedding (para búsqueda cosine PHP) +
 * tracking de chunking. Apps que necesiten el texto lo leen via:
 *   $file->extractedText()           ← full
 *   $file->chunkText($chunkIndex)    ← uno
 *
 * Ventaja: tablas MySQL drásticamente más ligeras, especialmente para PDFs
 * largos (text es del orden de cientos de KB por archivo). El storage R2/S3
 * absorbe el peso a coste ínfimo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laracrate_file_contents', function (Blueprint $table) {
            if (Schema::hasColumn('laracrate_file_contents', 'text')) {
                $table->dropColumn('text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('laracrate_file_contents', function (Blueprint $table) {
            if (! Schema::hasColumn('laracrate_file_contents', 'text')) {
                $table->longText('text')->nullable()->after('chunk_index');
            }
        });
    }
};
