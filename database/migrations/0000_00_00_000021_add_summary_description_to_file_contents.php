<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía `laracrate_file_contents` con campos resumen/descripción y
 * añade `skipped` al enum de status. Estos campos son opcionales (nullable
 * en el caso de summary/description, valor nuevo en el enum); apps que
 * no los necesiten pueden ignorarlos.
 *
 * `summary` / `description`: tras extraer el texto, una app puede destilarlo
 * con un LLM (one-liner descriptivo y resumen ejecutivo). Vive aquí porque
 * es metadata derivada del contenido del archivo y se reutiliza entre
 * features (búsqueda, listados, chat contextual).
 *
 * Status `skipped`: la app puede decidir no extraer un file (binario opaco,
 * tamaño excedido, política) sin marcarlo como failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('laracrate_file_contents', 'summary')) {
            Schema::table('laracrate_file_contents', function (Blueprint $table) {
                $table->text('summary')->nullable()->after('text');
                $table->text('description')->nullable()->after('summary');
            });
        }

        // Modificar el enum para incluir 'skipped'. Sin doctrine/dbal usamos
        // SQL directo. MySQL: ALTER TABLE ... MODIFY COLUMN.
        DB::statement("
            ALTER TABLE laracrate_file_contents
            MODIFY COLUMN status ENUM('pending','extracting','embedding','completed','failed','skipped')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('laracrate_file_contents', 'summary')) {
            Schema::table('laracrate_file_contents', function (Blueprint $table) {
                $table->dropColumn(['summary', 'description']);
            });
        }

        DB::statement("
            ALTER TABLE laracrate_file_contents
            MODIFY COLUMN status ENUM('pending','extracting','embedding','completed','failed')
            NOT NULL DEFAULT 'pending'
        ");
    }
};
