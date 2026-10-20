<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('staffs')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->decimal('total_marks', 10, 2)->default(0);
            $table->decimal('pass_mark', 10, 2)->default(50)->comment('Minimum % or marks to pass');
            $table->unsignedInteger('timer_minutes')->nullable()->comment('Optional timed quiz');
            $table->unsignedInteger('max_attempts')->nullable()->comment('NULL = unlimited attempts');
            $table->boolean('show_answers_after_submission')->default(true);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
