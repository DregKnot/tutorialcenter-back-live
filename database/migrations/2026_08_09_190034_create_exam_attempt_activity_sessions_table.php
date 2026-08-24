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
        Schema::create('exam_attempt_activity_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_attempt_id')
                ->constrained('exam_attempts')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            // One browser/app activity session for an attempt.
            $table->uuid('session_token');

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('active_seconds')->default(0);

            // submitted, abandoned, hidden, backgrounded, disconnected, idle, etc.
            $table->string('ended_reason')->nullable();

            $table->timestamp('last_heartbeat_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique('session_token');
            $table->index(['exam_attempt_id', 'started_at']);
            $table->index(['student_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_activity_sessions');
    }
};
