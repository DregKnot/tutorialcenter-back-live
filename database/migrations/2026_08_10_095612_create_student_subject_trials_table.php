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
        Schema::create('student_subject_trials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->foreignId('subject_id')
                ->constrained('subjects')
                ->cascadeOnDelete();

            $table->foreignId('exam_attempt_id')
                ->constrained('exam_attempts')
                ->cascadeOnDelete();

            // started, completed, abandoned, or invalidated
            $table->string('status')->default('started');

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            // Set once later attempts in this subject may contribute to milestones.
            $table->timestamp('became_eligible_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['student_id', 'subject_id'],
                'student_subject_trial_unique'
            );

            $table->unique('exam_attempt_id');
            $table->index(['student_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_subject_trials');
    }
};
