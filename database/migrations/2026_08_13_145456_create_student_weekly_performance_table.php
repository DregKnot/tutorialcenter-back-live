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
        Schema::create('student_weekly_performance', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            // ISO-style identifier such as 2026-W33.
            $table->string('week_key');
            $table->dateTime('week_starts_at');
            $table->dateTime('week_ends_at');
            $table->string('timezone')->default('Africa/Lagos');

            $table->unsignedInteger('completed_attempts')->default(0);
            $table->unsignedInteger('abandoned_attempts')->default(0);
            $table->unsignedBigInteger('total_questions')->default(0);
            $table->unsignedBigInteger('correct_answers')->default(0);
            $table->unsignedBigInteger('wrong_answers')->default(0);
            $table->unsignedBigInteger('skipped_questions')->default(0);
            $table->unsignedBigInteger('unanswered_questions')->default(0);

            $table->decimal('accuracy_percentage', 7, 4)->default(0);
            $table->unsignedBigInteger('active_seconds')->default(0);

            // Accuracy and improvement evaluation require six completed attempts.
            $table->boolean('is_eligible')->default(false);
            $table->dateTime('finalized_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(
                ['student_id', 'week_key'],
                'student_weekly_performance_unique'
            );

            $table->index(['week_key', 'is_eligible']);
            $table->index(['student_id', 'week_starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_weekly_performance');
    }
};
