<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engancha un file a una carpeta de `laracrate_folders` opcionalmente.
 *
 * `nullOnDelete` intencional: si se borra la carpeta sin pasar por el flujo
 * explícito (caso accidental), los archivos NO se evaporan, quedan huérfanos
 * en la raíz del fileable. El forceDelete intencional desde la app sí los
 * arrastra (llamando explícitamente $folder->forceDeleteRecursive() o similar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->foreignId('folder_id')
                ->nullable()
                ->after('fileable_id')
                ->constrained('laracrate_folders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });
    }
};
