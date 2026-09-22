<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Paystack reference can cover several courses, so one payment row is
     * written per enrollment sharing the same gateway_reference. Uniqueness
     * therefore belongs on (gateway_reference, course_enrollment_id).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_gateway_reference_unique');
            $table->unique(
                ['gateway_reference', 'course_enrollment_id'],
                'payments_reference_enrollment_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_reference_enrollment_unique');
            $table->unique('gateway_reference', 'payments_gateway_reference_unique');
        });
    }
};