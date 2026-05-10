<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laracrate_files', function (Blueprint $table) {
            $table->id();
            $table->ulid('slug')->unique();

            // Jerarquía: parent_id + variant. Top-level: ambos NULL.
            // Derivado: parent_id set + variant set ('thumbnail', 'preview', 'small', ...).
            $table->foreignId('parent_id')->nullable()->constrained('laracrate_files')->cascadeOnDelete();
            $table->string('variant', 50)->nullable();

            // Polymorphic owner — el modelo al que pertenece el archivo (Case, Property, ...).
            $table->nullableMorphs('fileable');

            // Polymorphic creator — quién/qué creó este registro (User, Agent, system cron, ...).
            $table->nullableMorphs('creator');

            // Polymorphic tenant — scope multi-tenant (Organization, Workspace, Team, ...).
            // Denormalizado desde el fileable para queries de billing, cuotas y aislamiento.
            $table->nullableMorphs('tenant');

            // Storage
            $table->string('disk');
            $table->string('path');
            $table->string('name');
            $table->string('original_name');
            $table->string('extension', 10);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('digest', 80)->nullable();

            // Classification
            $table->string('context')->default(config('laracrate.default_context', 'default'))->index();
            $table->string('collection')->default(config('laracrate.default_collection', 'default'))->index();
            $table->enum('type', ['image', 'video', 'audio', 'document'])->index();
            $table->string('category')->nullable()->index();

            // Access
            $table->enum('access', ['public', 'signed', 'stream'])->default('signed')->index();
            $table->string('visibility')->nullable()->index();
            $table->boolean('sensitive')->default(false)->index();
            $table->boolean('is_encrypted')->default(false);

            // Metadata
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('label', 100)->nullable();
            $table->boolean('default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('published')->default(true)->index();
            $table->boolean('is_verified')->default(false)->index();

            // Image / video specific
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();

            // JSON
            $table->json('metadata')->nullable();

            // Processing
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('processing_started_at')->nullable();

            // Audit
            $table->unsignedInteger('downloads_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['context', 'collection']);
            $table->index(['disk', 'path']);
            $table->index('created_at');
            $table->unique(['parent_id', 'variant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laracrate_files');
    }
};
