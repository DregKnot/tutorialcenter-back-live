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
        Schema::create('student_surveys', function (Blueprint $table) {
            $table->id();

            // Link to student if logged in
            $table->foreignId('student_id')
                  ->nullable()
                  ->constrained('students')
                  ->nullOnDelete();

            $table->string('survey_type')->default('learning_experience_v1');
            $table->string('full_name')->nullable();
            $table->string('exam_target')->nullable(); // JAMB, WAEC, NECO, GCE, etc.
            $table->json('subjects_taken')->nullable();
            $table->string('usage_duration')->nullable();

            // Section 2: Ratings (1-5)
            $table->unsignedTinyInteger('overall_rating')->nullable();
            $table->unsignedTinyInteger('live_classes_rating')->nullable();
            $table->unsignedTinyInteger('tutors_rating')->nullable();
            $table->unsignedTinyInteger('study_materials_rating')->nullable();
            $table->unsignedTinyInteger('cbt_rating')->nullable();
            $table->unsignedTinyInteger('platform_rating')->nullable();
            $table->string('navigation_ease')->nullable();

            // Section 4 & 5: Courses & Video Preferences
            $table->string('courses_awareness')->nullable();
            $table->unsignedTinyInteger('courses_utility_rating')->nullable();
            $table->string('preferred_video_format')->nullable();
            $table->string('preferred_video_length')->nullable();

            // Section 10: NPS (0-10)
            $table->unsignedTinyInteger('nps_score')->nullable();

            // Section 11: Followup
            $table->boolean('wants_followup')->default(false);
            $table->string('whatsapp_number')->nullable();

            // Complete raw payload of all answers
            $table->json('responses');

            // Metadata
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            // Indices for fast analytics querying
            $table->index('survey_type');
            $table->index('exam_target');
            $table->index('preferred_video_format');
            $table->index('overall_rating');
            $table->index('nps_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_surveys');
    }
};
