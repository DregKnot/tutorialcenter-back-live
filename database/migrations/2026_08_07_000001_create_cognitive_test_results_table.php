<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cognitive_tests', function (Blueprint $table) {
            $table->id();
            $table->string('student_name');
            $table->string('school');
            $table->timestamp('test_started_at')->useCurrent();
            $table->timestamp('test_ended_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cognitive_tests');
    }
};
