<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->string('type')->default('multiple_choice')
                  ->comment('multiple_choice, true_false, short_answer');
            $table->text('question');
            $table->decimal('marks', 8, 2)->default(1);
            $table->unsignedInteger('order')->default(0);
            $table->text('explanation')->nullable()->comment('Shown after submission to explain the correct answer');
            $table->string('image')->nullable()->comment('Optional reference image path');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['quiz_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
