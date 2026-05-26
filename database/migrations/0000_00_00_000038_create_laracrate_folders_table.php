<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carpetas para organizar files. Árbol parent/child con path denormalizado.
 *
 * Modelo: igual que `laracrate_files`, polimórfico vía `folderable` (User, Org,
 * Case, lo que sea). Permite carpetas vacías que persisten en BD (a diferencia
 * del modelo S3 puro donde la "carpeta" emerge del prefijo de los files).
 *
 * El path denormalizado (`name1/name2/name3`) acelera listings y se mantiene
 * coherente vía el FolderObserver — la fuente de verdad es `parent_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laracrate_folders', function (Blueprint $table) {
            $table->id();

            // Dueño operacional del árbol — equivalente a files.fileable.
            $table->nullableMorphs('folderable');

            // Posición en el árbol. parent_id NULL = raíz.
            $table->foreignId('parent_id')->nullable()->constrained('laracrate_folders')->cascadeOnDelete();

            // Nombre del segmento ("2025") + path denormalizado desde raíz
            // ("Contratos/2025"). El observer recalcula el path en cada save.
            $table->string('name');
            $table->string('path', 1024);

            // Quién/qué la creó. Mismo morph que files.creator.
            $table->nullableMorphs('creator');

            // Metadata libre: color, icon, sort_order custom, etc.
            $table->json('metadata')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Unicidad lógica del path bajo un mismo folderable. Evita dos
            // carpetas con el mismo nombre/path en el mismo árbol.
            $table->unique(['folderable_type', 'folderable_id', 'path'], 'lf_folderable_path_unique');

            // Listing rápido de hijos directos de una carpeta dentro del árbol.
            $table->index(['folderable_type', 'folderable_id', 'parent_id'], 'lf_folderable_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laracrate_folders');
    }
};
