<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

class AchievementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $achievements = [
            [
                'code' => 'onboarding.welcome_aboard',
                'name' => 'Welcome Aboard',
                'description' => 'Created an account on Tutorial Center Africa.',
                'category' => 'onboarding',
                'type' => 'badge',
                'scope' => 'once',
                'progressive' => true,
                'display_order' => 1,
                'requirements' => [
                    'event' => 'student_registered',
                ],
            ],
            [
                'code' => 'onboarding.profile_complete',
                'name' => 'Profile Complete',
                'description' => 'Completed all required profile information.',
                'category' => 'onboarding',
                'type' => 'badge',
                'scope' => 'once',
                'progressive' => true,
                'display_order' => 2,
                'requirements' => [
                    'event' => 'profile_completed',
                ],
            ],
            [
                'code' => 'onboarding.ready_to_learn',
                'name' => 'Ready to Learn',
                'description' => 'Selected an exam type and subjects.',
                'category' => 'onboarding',
                'type' => 'badge',
                'scope' => 'once',
                'progressive' => true,
                'display_order' => 3,
                'requirements' => [
                    'event' => 'exam_type_and_subjects_selected',
                ],
            ],
            [
                'code' => 'onboarding.first_step',
                'name' => 'First Step',
                'description' => 'Started the first exam-practice attempt.',
                'category' => 'onboarding',
                'type' => 'badge',
                'scope' => 'once',
                'progressive' => true,
                'display_order' => 4,
                'requirements' => [
                    'event' => 'exam_attempt_started',
                ],
            ],
            [
                'code' => 'onboarding.first_answer',
                'name' => 'First Answer',
                'description' => 'Submitted the first answer in exam practice.',
                'category' => 'onboarding',
                'type' => 'badge',
                'scope' => 'once',
                'progressive' => true,
                'display_order' => 5,
                'requirements' => [
                    'event' => 'exam_answer_submitted',
                ],
            ],
            [
                'code' => 'onboarding.practice_starter',
                'name' => 'Practice Starter',
                'description' => 'Completed the first exam-practice attempt.',
                'category' => 'onboarding',
                'type' => 'badge',
                'scope' => 'once',
                'progressive' => true,
                'display_order' => 6,
                'requirements' => [
                    'event' => 'exam_attempt_completed',
                ],
            ],
            [
                'code' => 'onboarding.first_achievement',
                'name' => 'First Achievement',
                'description' => 'Earned the first qualifying non-onboarding achievement.',
                'category' => 'onboarding',
                'type' => 'badge',
                'scope' => 'once',
                'progressive' => true,
                'display_order' => 7,
                'requirements' => [
                    'event' => 'qualifying_non_onboarding_achievement_awarded',
                    'excluded_category' => 'onboarding',
                ],
            ],
            [
                'code' => 'practice.question_explorer',
                'name' => 'Question Explorer',
                'description' => 'Answered 50 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 1,
                'requirements' => ['eligible_answers' => 50],
            ],
            [
                'code' => 'practice.question_challenger',
                'name' => 'Question Challenger',
                'description' => 'Answered 100 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 2,
                'requirements' => ['eligible_answers' => 100],
            ],
            [
                'code' => 'practice.knowledge_hunter',
                'name' => 'Knowledge Hunter',
                'description' => 'Answered 250 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 3,
                'requirements' => ['eligible_answers' => 250],
            ],
            [
                'code' => 'practice.study_warrior',
                'name' => 'Study Warrior',
                'description' => 'Answered 500 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 4,
                'requirements' => ['eligible_answers' => 500],
            ],
            [
                'code' => 'practice.academic_hero',
                'name' => 'Academic Hero',
                'description' => 'Answered 1,000 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 5,
                'requirements' => ['eligible_answers' => 1000],
            ],
            [
                'code' => 'practice.practice_champion',
                'name' => 'Practice Champion',
                'description' => 'Answered 2,500 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 6,
                'requirements' => ['eligible_answers' => 2500],
            ],
            [
                'code' => 'practice.learning_machine',
                'name' => 'Learning Machine',
                'description' => 'Answered 5,000 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 7,
                'requirements' => ['eligible_answers' => 5000],
            ],
            [
                'code' => 'practice.master_scholar',
                'name' => 'Master Scholar',
                'description' => 'Answered 10,000 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 8,
                'requirements' => ['eligible_answers' => 10000],
            ],
            [
                'code' => 'practice.education_legend',
                'name' => 'Education Legend',
                'description' => 'Answered 25,000 eligible exam-practice questions.',
                'category' => 'practice_milestone',
                'type' => 'badge',
                'scope' => 'lifetime',
                'progressive' => true,
                'display_order' => 9,
                'requirements' => ['eligible_answers' => 25000],
            ],
        ];

        foreach ($achievements as $definition) {
            Achievement::updateOrCreate(
                ['code' => $definition['code']],
                array_merge(
                    [
                        'tier' => null,
                        'repeatable' => false,
                        'icon_path' => null,
                        'is_active' => true,
                        'starts_at' => null,
                        'ends_at' => null,
                    ],
                    $definition
                )
            );
        }
    }
}
