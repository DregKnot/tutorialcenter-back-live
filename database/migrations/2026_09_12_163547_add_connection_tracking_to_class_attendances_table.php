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
        Schema::table('class_attendances', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('visit_started_at')->nullable();
            $table->string('connection_state', 20)->nullable()->index();
            $table->unsignedInteger('visit_number')->default(0);
            $table->unsignedBigInteger('connected_seconds')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_attendances', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'visit_started_at', 'connection_state', 'visit_number', 'connected_seconds']);
        });
    }
};
