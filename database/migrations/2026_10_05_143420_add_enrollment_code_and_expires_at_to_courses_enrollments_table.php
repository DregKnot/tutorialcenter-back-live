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
        Schema::table('courses_enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('courses_enrollments', 'enrollment_code')) {
                $table->string('enrollment_code', 64)->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('courses_enrollments', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('end_date');
            }
            if (!Schema::hasColumn('courses_enrollments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('expires_at');
            }
            if (!Schema::hasColumn('courses_enrollments', 'termination_reason')) {
                $table->string('termination_reason', 255)->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses_enrollments', function (Blueprint $table) {
            $columns = ['enrollment_code', 'expires_at', 'paid_at', 'termination_reason'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('courses_enrollments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
