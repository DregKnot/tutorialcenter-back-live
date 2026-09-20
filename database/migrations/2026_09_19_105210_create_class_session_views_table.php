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
        Schema::create('class_session_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('class_session_id')
                ->constrained('class_sessions')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->unsignedInteger('view_count')->default(1);
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();

            $table->unique(['class_session_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_session_views');
    }
};
