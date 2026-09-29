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
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->boolean('is_jamb')->default(false)->after('timer');
            $table->json('exam_year_ids')->nullable()->after('is_jamb');
            $table->json('subject_scores')->nullable()->after('exam_year_ids');
            $table->unsignedInteger('jamb_score')->nullable()->after('subject_scores');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn(['is_jamb', 'exam_year_ids', 'subject_scores', 'jamb_score']);
        });
    }
};
