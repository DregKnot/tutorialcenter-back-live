<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('staffs')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->enum('status', ['draft', 'published', 'closed'])->default('draft')->index();
            $table->Decimal('total_marks', 10, 2)->default(0);
            $table->Decimal('pass_mark', 10, 2)->default(50);
            $table->unsignedInteger('timer_minutes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['class_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
