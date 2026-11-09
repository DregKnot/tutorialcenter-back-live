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
        Schema::create('student_subject_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('course_enrollment_id')->nullable()->constrained('courses_enrollments')->nullOnDelete();
            $table->string('action_type', 20)->default('swap');
            $table->foreignId('old_subject_id')->nullable()->constrained('subjects')->onDelete('cascade');
            $table->foreignId('new_subject_id')->nullable()->constrained('subjects')->onDelete('cascade');
            $table->text('reason');
            $table->foreignId('changed_by_staff_id')->nullable()->constrained('staffs')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'acknowledged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_subject_changes');
    }
};
