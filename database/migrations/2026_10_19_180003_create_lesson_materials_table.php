<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('title');
            $table->enum('type', ['video', 'document', 'link', 'image', 'audio'])->default('video');
            $table->text('url')->nullable()->comment('External URL or cloud storage path');
            $table->string('file_path')->nullable()->comment('Local/S3 storage path for uploaded files');
            $table->unsignedInteger('duration_seconds')->nullable()->comment('For video/audio materials');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_preview')->default(false)->comment('Free preview material visible before enrollment');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lesson_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_materials');
    }
};
