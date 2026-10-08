<?php

use App\Models\Achievement;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Achievement::firstOrCreate(
            ['code' => 'special_event.survey_pioneer'],
            [
                'name' => 'Survey Pioneer',
                'description' => 'Completed and submitted the Student Experience & Learning Feedback Survey.',
                'category' => 'special_event',
                'type' => 'badge',
                'tier' => 'gold',
                'scope' => 'once',
                'repeatable' => false,
                'progressive' => false,
                'display_order' => 1,
                'requirements' => [
                    'event' => 'learning_survey',
                    'action' => 'submit_survey',
                ],
                'is_active' => true,
            ]
        );
    }

    public function down(): void
    {
        Achievement::where('code', 'special_event.survey_pioneer')->delete();
    }
};
