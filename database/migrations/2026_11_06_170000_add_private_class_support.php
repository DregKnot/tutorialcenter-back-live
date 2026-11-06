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
        // 1. Add private class columns to `classes` table
        Schema::table('classes', function (Blueprint $table) {
            if (!Schema::hasColumn('classes', 'is_private')) {
                $table->boolean('is_private')->default(false)->after('status')->index();
            }
            if (!Schema::hasColumn('classes', 'private_student_id')) {
                $table->foreignId('private_student_id')
                    ->nullable()
                    ->after('is_private')
                    ->constrained('students')
                    ->nullOnDelete();
            }
        });

        // 2. Create `private_class_enrollments` table for financial audit and session quota lifecycle
        if (!Schema::hasTable('private_class_enrollments')) {
            Schema::create('private_class_enrollments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('class_id')
                    ->constrained('classes')
                    ->cascadeOnDelete();

                $table->foreignId('student_id')
                    ->constrained('students')
                    ->cascadeOnDelete();

                $table->foreignId('course_enrollment_id')
                    ->nullable()
                    ->constrained('courses_enrollments')
                    ->nullOnDelete();

                $table->decimal('amount_paid', 12, 2)->default(0.00);
                $table->string('currency', 10)->default('NGN');
                $table->string('payment_reference', 64)->unique();
                $table->string('payment_method', 32)->default('bank_transfer'); // bank_transfer, card, manual, pos

                $table->unsignedInteger('session_quota')->default(4);
                $table->unsignedInteger('sessions_completed')->default(0);

                $table->date('starts_at');
                $table->date('expires_at');

                $table->enum('status', ['active', 'completed', 'expired', 'cancelled'])
                    ->default('active')
                    ->index();

                $table->text('notes')->nullable();

                $table->softDeletes();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('private_class_enrollments');

        Schema::table('classes', function (Blueprint $table) {
            if (Schema::hasColumn('classes', 'private_student_id')) {
                $table->dropForeign(['private_student_id']);
                $table->dropColumn('private_student_id');
            }
            if (Schema::hasColumn('classes', 'is_private')) {
                $table->dropColumn('is_private');
            }
        });
    }
};

