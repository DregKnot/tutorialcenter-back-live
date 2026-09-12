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
        Schema::create('student_activity_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('delivery_key', 64)->unique();
            $table->unsignedBigInteger('student_id')->index();
            $table->string('event_type', 60);
            $table->string('recipient_type');
            $table->unsignedBigInteger('recipient_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_activity_deliveries');
    }
};
