<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companion table para texto extraído + embeddings.
 *
 * 1 fila por chunk de texto. Si la collection tiene `chunk_size: 0` (sin
 * chunking) se guarda 1 fila por File con todo el texto. La FK a `files`
 * es cascade — borrar el File borra los contents.
 *
 * El embedding va como JSON; el package no asume vector DB. Si la app
 * quiere indexar en pgvector/Meilisearch/Qdrant, escucha el evento
 * `FileContentEmbedded` y sincroniza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laracrate_file_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained('laracrate_files')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index')->default(0);

            $table->longText('text')->nullable();
            $table->json('embedding')->nullable();

            $table->unsignedInteger('tokens')->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();

            $table->json('metadata')->nullable();

            $table->enum('status', ['pending', 'extracting', 'embedding', 'completed', 'failed'])->default('pending');
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(['file_id', 'chunk_index']);
            $table->index(['provider', 'model']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laracrate_file_contents');
    }
};
