<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buckets dedicados por tenant. Cada fila sobrescribe UN disk del config
 * (el `base_disk`) para los files de UN tenant. Granularidad por disk
 * (no por collection): si el config tiene 3 disks ('document', 'media',
 * 'attachment'), el tenant puede activar bucket dedicado para 0..3 de
 * ellos independientemente.
 *
 * Convención de `laracrate_files.disk`:
 *   'document'  → bucket del config (shared, default).
 *   'tb:{id}'   → bucket dedicado, resuelto vía esta tabla.
 *
 * Diseño optimizado para R2 / S3 SaaS: key/secret/endpoint/region viven
 * en el config del SaaS (.env), aquí solo se guarda el bucket name y
 * opcionalmente public_url. `credentials` es opcional para casos BYOA
 * (cliente trae su propia cuenta) — cifrado con APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laracrate_tenant_buckets', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_type');
            $table->unsignedBigInteger('tenant_id');

            // Disk del config global que esta fila sobrescribe. Ej. 'document'.
            $table->string('base_disk');

            // Bucket que reemplaza al del base_disk.
            $table->string('bucket');

            // URL pública del bucket si está expuesto (dominio custom o
            // r2.dev). Sobrescribe la `url` del base_disk si presente.
            $table->string('public_url')->nullable();

            // BYOA: anula key/secret/endpoint/region del base. Para tenants
            // que aportan su propia cuenta R2/S3. Si NULL, se hereda todo
            // del base_disk del config global.
            $table->longText('credentials')->nullable();

            $table->boolean('is_active')->default(true);

            // Label visible en UI (ej. "crowd-documents-garza-asociados").
            $table->string('label')->nullable();

            $table->timestamps();

            $table->unique(['tenant_type', 'tenant_id', 'base_disk'], 'laracrate_tenant_buckets_unique');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laracrate_tenant_buckets');
    }
};
