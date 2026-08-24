<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamQuestionInteraction;
use App\Models\Student;
use App\Models\StudentAchievement;
use Throwable;

class OnboardingAchievementService
{
    public function __construct(
        protected AchievementAwardService $awardService
    ) {}

    public function accountCreated(Student $student): ?StudentAchievement
    {
        return $this->safely(fn () => $this->awardService->award(
            $student,
            'onboarding.welcome_aboard',
            context: [
                'metadata' => [
                    'source' => 'student_registration',
                    'registered_at' => $student->created_at,
                ],
            ]
        ));
    }

    public function profileCompleted(Student $student): ?StudentAchievement
    {
        return $this->safely(fn () => $this->awardService->award(
            $student,
            'onboarding.profile_complete',
            context: [
                'metadata' => [
                    'source' => 'student_profile',
                ],
            ]
        ));
    }

    public function awardProfileIfComplete(
        Student $student
    ): ?StudentAchievement {
        $requiredFields = [
            'firstname',
            'surname',
            'gender',
            'date_of_birth',
            'location',
            'department',
        ];

        foreach ($requiredFields as $field) {
            if (blank($student->{$field})) {
                return null;
            }
        }

        return $this->profileCompleted($student);
    }

    public function readyToLearn(
        Student $student,
        array $metadata = []
    ): ?StudentAchievement {
        return $this->safely(fn () => $this->awardService->award(
            $student,
            'onboarding.ready_to_learn',
            context: [
                'metadata' => array_merge(
                    ['source' => 'student_enrollment'],
                    $metadata
                ),
            ]
        ));
    }

    public function firstPracticeStarted(
        Student $student,
        ExamAttempt $attempt
    ): ?StudentAchievement {
        return $this->awardForAttempt(
            $student,
            $attempt,
            'onboarding.first_step'
        );
    }

    public function firstAnswerSubmitted(
        Student $student,
        ExamAttempt $attempt,
        ?ExamQuestionInteraction $interaction = null
    ): ?StudentAchievement {
        return $this->awardForAttempt(
            $student,
            $attempt,
            'onboarding.first_answer',
            $interaction ? [
                'interaction_id' => $interaction->id,
                'question_id' => $interaction->past_question_id,
            ] : []
        );
    }

    public function firstPracticeCompleted(
        Student $student,
        ExamAttempt $attempt
    ): ?StudentAchievement {
        return $this->awardForAttempt(
            $student,
            $attempt,
            'onboarding.practice_starter'
        );
    }

    public function firstQualifyingAchievementAwarded(
        StudentAchievement $earnedAchievement
    ): ?StudentAchievement {
        $earnedAchievement->loadMissing(['student', 'achievement']);

        if ($earnedAchievement->achievement->category === 'onboarding') {
            return null;
        }

        return $this->safely(fn () => $this->awardService->award(
            $earnedAchievement->student,
            'onboarding.first_achievement',
            context: [
                'metadata' => [
                    'source' => 'student_achievement',
                    'source_student_achievement_id' => $earnedAchievement->id,
                    'source_achievement_code' => $earnedAchievement->achievement->code,
                ],
            ]
        ));
    }

    private function awardForAttempt(
        Student $student,
        ExamAttempt $attempt,
        string $achievementCode,
        array $metadata = []
    ): ?StudentAchievement {
        return $this->safely(fn () => $this->awardService->award(
            $student,
            $achievementCode,
            context: [
                'exam_attempt_id' => $attempt->id,
                'subject_id' => $attempt->examYear()->value('subject_id'),
                'metadata' => array_merge(
                    ['source' => 'exam_practice'],
                    $metadata
                ),
            ]
        ));
    }

    private function safely(callable $callback): ?StudentAchievement
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
