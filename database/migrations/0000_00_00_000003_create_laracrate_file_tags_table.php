<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('laracrate_file_tags', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_type', 64)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('context_type', 64)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();

            $table->string('name');
            $table->string('description')->nullable();
            $table->string('color', 16)->nullable();
            $table->json('allowed_extensions')->nullable();
            $table->unsignedInteger('max_files_per_creator')->nullable();
            $table->unsignedInteger('max_files_total')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_type', 'tenant_id'], 'ft_tenant_idx');
            $table->index(['context_type', 'context_id'], 'ft_context_idx');
        });

        Schema::create('laracrate_file_tag_pivot', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('file_id');
            $table->unsignedBigInteger('file_tag_id');
            $table->timestamps();

            $table->foreign('file_id')
                ->references('id')->on('laracrate_files')
                ->cascadeOnDelete();
            $table->foreign('file_tag_id')
                ->references('id')->on('laracrate_file_tags')
                ->cascadeOnDelete();

            $table->unique(['file_id', 'file_tag_id'], 'ftp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laracrate_file_tag_pivot');
        Schema::dropIfExists('laracrate_file_tags');
    }
};
