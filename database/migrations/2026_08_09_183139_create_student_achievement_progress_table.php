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
        Schema::create('student_achievement_progress', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            // Examples: eligible_exam_answers, lifetime_active_exam_seconds,
            // completed_exam_attempts, current_learning_streak.
            $table->string('progress_key');

            // Optional subject scope for progress such as subject-level totals.
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subjects')
                ->nullOnDelete();

            $table->unsignedBigInteger('integer_value')->default(0);
            $table->decimal('decimal_value', 12, 4)->nullable();
            $table->unsignedBigInteger('duration_seconds')->default(0);

            // Additional state, such as the last streak date or calculation details.
            $table->json('metadata')->nullable();

            // Used by event consumers to resume safely and avoid duplicate processing.
            $table->string('last_processed_event_id')->nullable();
            $table->timestamp('calculated_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['student_id', 'progress_key', 'subject_id'],
                'student_progress_scope_unique'
            );

            $table->index(['student_id', 'progress_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_achievement_progress');
    }
};
