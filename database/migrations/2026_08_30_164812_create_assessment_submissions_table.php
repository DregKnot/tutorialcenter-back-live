<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('status', ['in_progress', 'submitted', 'graded', 'absent'])->default('in_progress')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->Decimal('score', 10, 2)->default(0);
            $table->Decimal('total_marks', 10, 2)->default(0);
            $table->Decimal('percentage', 10, 2)->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('staffs')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['assessment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_submissions');
    }
};
