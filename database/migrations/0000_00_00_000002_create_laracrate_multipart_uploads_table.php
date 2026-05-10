<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de sesiones de upload multipart S3/R2.
 *
 * Una fila por upload activo. Solo entran archivos grandes (> threshold,
 * default 100 MB). Los pequeños siguen yendo por single PUT y no la tocan.
 *
 * Vida típica de una fila: minutos u horas. Al completar o abortar, la fila
 * se marca como tal pero NO se borra inmediatamente — se mantiene como audit
 * trail. Un cron `laracrate:abort-stale-multipart` aborta + marca expired las
 * sesiones activas con expires_at < now().
 *
 * Importante: las partes ya subidas a S3/R2 ocupan storage hasta que se
 * complete o aborte el upload. Por eso el cron es crítico — sin él, los
 * abandonos te facturan storage indefinidamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laracrate_multipart_uploads', function (Blueprint $table) {
            $table->id();

            // Identificación del upload en el provider.
            $table->string('upload_id')->unique();
            $table->string('disk');
            $table->text('key');

            // Metadata del archivo.
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('expected_size');
            $table->unsignedInteger('part_size');
            $table->unsignedInteger('total_parts');

            // pending → active al recibir el primer part. completed/aborted/expired terminales.
            $table->enum('status', ['active', 'completed', 'aborted', 'expired'])
                ->default('active');

            // Quién lo creó (mismo morph que files.creator).
            $table->string('creator_type')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();

            // Multi-tenancy (mismo morph que files.tenant).
            $table->string('tenant_type')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();

            // Destino del File una vez completado (opcionales en init,
            // se pueden inferir o pasar al complete).
            $table->string('fileable_type')->nullable();
            $table->unsignedBigInteger('fileable_id')->nullable();
            $table->string('collection')->nullable();

            // File row creada al completar — null hasta entonces.
            $table->foreignId('file_id')->nullable()->constrained('laracrate_files')->nullOnDelete();

            $table->json('metadata')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('aborted_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
            $table->index(['creator_type', 'creator_id']);
            $table->index(['tenant_type', 'tenant_id']);
            $table->index(['fileable_type', 'fileable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laracrate_multipart_uploads');
    }
};
