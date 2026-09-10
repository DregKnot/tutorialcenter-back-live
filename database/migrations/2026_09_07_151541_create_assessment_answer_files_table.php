<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_answer_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained('assessment_answers')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->index('file_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_answer_files');
    }
};
