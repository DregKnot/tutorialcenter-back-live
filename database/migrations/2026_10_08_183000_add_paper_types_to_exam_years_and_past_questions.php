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
        // 1. exam_years (with idempotent column checks in case partially run)
        Schema::table('exam_years', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_years', 'has_paper_types')) {
                $table->boolean('has_paper_types')->default(false)->after('year');
            }
            if (!Schema::hasColumn('exam_years', 'paper_types')) {
                $table->json('paper_types')->nullable()->after('has_paper_types');
            }
        });

        // 2. Add paper_type column and an independent index for exam_year_id.
        // In MySQL/InnoDB, dropping past_question_number_unique fails with Error 1553
        // because the foreign key past_questions_exam_year_id_foreign relies on it.
        // Adding an explicit index on exam_year_id first satisfies the FK requirement.
        Schema::table('past_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('past_questions', 'paper_type')) {
                $table->string('paper_type', 50)->nullable()->after('question_type');
            }
            $table->index('exam_year_id', 'past_questions_exam_year_id_index');
        });

        // 3. Drop old unique and add composite unique incorporating paper_type.
        // Now safe because exam_year_id has past_questions_exam_year_id_index.
        Schema::table('past_questions', function (Blueprint $table) {
            $table->dropUnique('past_question_number_unique');
            $table->unique(['exam_year_id', 'paper_type', 'question_number'], 'past_question_year_type_number_unique');
        });

        // 4. exam_attempts
        Schema::table('exam_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('exam_attempts', 'paper_type')) {
                $table->string('paper_type', 50)->nullable()->after('exam_year_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('exam_attempts', 'paper_type')) {
                $table->dropColumn('paper_type');
            }
        });

        Schema::table('past_questions', function (Blueprint $table) {
            $table->dropUnique('past_question_year_type_number_unique');
            $table->unique(['exam_year_id', 'question_number'], 'past_question_number_unique');
            $table->dropIndex('past_questions_exam_year_id_index');
            if (Schema::hasColumn('past_questions', 'paper_type')) {
                $table->dropColumn('paper_type');
            }
        });

        Schema::table('exam_years', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('exam_years', 'has_paper_types')) {
                $columnsToDrop[] = 'has_paper_types';
            }
            if (Schema::hasColumn('exam_years', 'paper_types')) {
                $columnsToDrop[] = 'paper_types';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
