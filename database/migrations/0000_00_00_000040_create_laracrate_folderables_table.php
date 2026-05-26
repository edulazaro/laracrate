<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counter agregado de almacenamiento por (folderable, collection).
 *
 * Una fila por dueño + collection, mantenida en tiempo real por el
 * FileObserver SIEMPRE QUE la collection tenga `track_usage = true` en su
 * config. Lectura instantánea (vs SUM(size) repetido) — pensado para chequear
 * cuotas antes de cada upload sin escanear miles de filas.
 *
 * El comando `laracrate:recompute-usage` recalcula desde laracrate_files si
 * sospechas drift (ej. tras importar archivos a mano).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laracrate_folderables', function (Blueprint $table) {
            $table->id();
            $table->morphs('folderable');
            $table->string('collection');

            $table->unsignedBigInteger('total_size_bytes')->default(0);
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedInteger('folders_count')->default(0);

            $table->timestamp('last_recomputed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['folderable_type', 'folderable_id', 'collection'],
                'laracrate_folderables_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laracrate_folderables');
    }
};
