<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polymorphic owner — destinatario / dueño semántico del archivo, distinto
     * del fileable y del creator. Casos típicos:
     *
     *  - PDF auto-generado desde plantilla: creator=admin, owner=cliente, fileable=case.
     *  - Documento subido por un advisor en nombre de un cliente: creator=advisor, owner=cliente.
     *  - Subida directa del propio usuario: owner se deja NULL y fallback a creator
     *    vía `$file->effectiveOwner()`.
     *
     * Mantener NULL cuando el creator y el owner coinciden — el accessor
     * `effectiveOwner()` ya cae al creator.
     */
    public function up(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->nullableMorphs('owner');
        });
    }

    public function down(): void
    {
        Schema::table('laracrate_files', function (Blueprint $table) {
            $table->dropMorphs('owner');
        });
    }
};
