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
        Schema::create('special_event_calendars', function (Blueprint $table) {
            $table->id();

            $table->string('country_code', 2);
            $table->string('event_code');
            $table->string('event_key');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone')->default('Africa/Lagos');
            $table->unsignedInteger('minimum_exam_practices_started')->default(1);
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['country_code', 'event_code', 'event_key'],
                'special_event_calendar_identity_unique'
            );
            $table->index(
                ['country_code', 'starts_at', 'ends_at'],
                'special_event_calendar_window_index'
            );
            $table->index(
                ['is_active', 'event_code'],
                'special_event_calendar_active_event_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('special_event_calendars');
    }
};
