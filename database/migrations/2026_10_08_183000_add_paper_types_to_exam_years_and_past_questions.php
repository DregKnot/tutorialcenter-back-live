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
        Schema::table('exam_years', function (Blueprint $table) {
            $table->boolean('has_paper_types')->default(false)->after('year');
            $table->json('paper_types')->nullable()->after('has_paper_types');
        });

        Schema::table('past_questions', function (Blueprint $table) {
            $table->string('paper_type', 50)->nullable()->after('question_type');

            // Drop old unique constraint on (exam_year_id, question_number)
            $table->dropUnique('past_question_number_unique');

            // Add composite unique constraint incorporating paper_type
            $table->unique(['exam_year_id', 'paper_type', 'question_number'], 'past_question_year_type_number_unique');
        });

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
            $table->dropColumn('paper_type');
        });

        Schema::table('exam_years', function (Blueprint $table) {
            $table->dropColumn(['has_paper_types', 'paper_types']);
        });
    }
};
