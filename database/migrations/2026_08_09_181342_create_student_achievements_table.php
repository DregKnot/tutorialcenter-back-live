<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_achievements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->foreignId('achievement_id')
                ->constrained('achievements')
                ->cascadeOnDelete();

            $table->foreignId('exam_attempt_id')
                ->nullable()
                ->constrained('exam_attempts')
                ->nullOnDelete();

            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subjects')
                ->nullOnDelete();

            $table->string('tier')->nullable();
            $table->string('period_key')->nullable();
            $table->string('occurrence_key');
            $table->json('metadata')->nullable();
            $table->timestamp('awarded_at');

            $table->timestamps();

            $table->unique(
                ['student_id', 'achievement_id', 'occurrence_key'],
                'student_achievement_occurrence_unique'
            );

            $table->index(['student_id', 'awarded_at']);
            $table->index(['achievement_id', 'awarded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_achievements');
    }
};
