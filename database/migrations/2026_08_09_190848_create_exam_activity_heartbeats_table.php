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
        Schema::create('exam_activity_heartbeats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('exam_attempt_id')
                ->constrained('exam_attempts')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->uuid('session_token');
            $table->foreignId('question_id')
                ->nullable()
                ->constrained('past_questions')
                ->nullOnDelete();

            $table->uuid('client_event_id')->unique();

            $table->boolean('page_visible')->default(true);
            $table->boolean('app_focused')->default(true);
            $table->string('interaction_type')->nullable();

            // Client time is for diagnostics; received_at is authoritative.
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['exam_attempt_id', 'occurred_at']);
            $table->index(['session_token', 'received_at']);
            $table->index(['student_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_activity_heartbeats');
    }
};
