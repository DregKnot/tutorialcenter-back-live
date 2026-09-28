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
        if (Schema::hasTable('exam_attempts') && !Schema::hasColumn('exam_attempts', 'timer')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->unsignedInteger('timer')->default(50)->after('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('exam_attempts') && Schema::hasColumn('exam_attempts', 'timer')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->dropColumn('timer');
            });
        }
    }
};
