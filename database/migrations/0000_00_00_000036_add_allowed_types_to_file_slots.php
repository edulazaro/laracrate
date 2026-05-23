<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `laracrate_file_slots.allowed_types`: array de FileType (document|image|
 * video|audio) que el slot acepta. Combinado con `allowed_extensions` —
 * cuando ambos están poblados se aplica AND.
 *
 * NULL o array vacío → sin restricción de type (cualquier tipo entra).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laracrate_file_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('laracrate_file_slots', 'allowed_types')) {
                $table->json('allowed_types')->nullable()->after('allowed_extensions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('laracrate_file_slots', function (Blueprint $table) {
            if (Schema::hasColumn('laracrate_file_slots', 'allowed_types')) {
                $table->dropColumn('allowed_types');
            }
        });
    }
};
