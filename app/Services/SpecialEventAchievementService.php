<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\SpecialEventCalendar;
use App\Models\Student;
use Carbon\CarbonInterface;

class SpecialEventAchievementService
{
    public function __construct(
        protected AchievementAwardService $awardService,
        protected OnboardingAchievementService $onboardingAchievementService
    ) {}

    /**
     * Evaluate configured events and return only newly-created awards.
     *
     * Each event must contain: code, event_key, starts_at and ends_at.
     */
    public function evaluate(
        Student $student,
        array $events,
        ?CarbonInterface $asOf = null
    ): array {
        $awards = [];

        foreach ($events as $event) {
            if (! $this->validEvent($event)) {
                continue;
            }

            $startsAt = $event['starts_at'];
            $endsAt = $event['ends_at'];

            if ($asOf && $asOf->lessThan($endsAt)) {
                continue;
            }

            $minimumAttempts = max(
                1,
                (int) ($event['minimum_exam_practices_started'] ?? 1)
            );

            $participated = $student->examAttempts()
                ->whereNotNull('started_at')
                ->whereBetween('started_at', [$startsAt, $endsAt])
                ->whereIn('status', [
                    ExamAttempt::COMPLETED,
                    ExamAttempt::ABANDONED,
                    ExamAttempt::IN_PROGRESS,
                ])
                ->count() >= $minimumAttempts;

            if (! $participated) {
                continue;
            }

            $award = $this->awardService->award(
                $student,
                $event['code'],
                occurrenceKey: $event['event_key'],
                context: [
                    'period_key' => $event['event_key'],
                    'metadata' => [
                        'source' => 'special_event',
                        'event_key' => $event['event_key'],
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                    ],
                ]
            );

            $this->onboardingAchievementService
                ->firstQualifyingAchievementAwarded($award);

            if ($award->wasRecentlyCreated) {
                $awards[] = $award;
            }
        }

        return $awards;
    }

    public function evaluateCountryEvents(
        Student $student,
        string $countryCode,
        ?CarbonInterface $asOf = null
    ): array {
        $asOf ??= now();

        $events = SpecialEventCalendar::query()
            ->active()
            ->forCountry($countryCode)
            ->endedBy($asOf)
            ->orderBy('ends_at')
            ->get()
            ->map(fn (SpecialEventCalendar $event) => [
                'code' => $event->event_code,
                'event_key' => $event->event_key,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'minimum_exam_practices_started' => $event->minimum_exam_practices_started,
            ])
            ->all();

        return $this->evaluate($student, $events, $asOf);
    }

    private function validEvent(array $event): bool
    {
        return isset(
            $event['code'],
            $event['event_key'],
            $event['starts_at'],
            $event['ends_at']
        ) && $event['starts_at'] instanceof CarbonInterface
            && $event['ends_at'] instanceof CarbonInterface
            && $event['starts_at']->lessThanOrEqualTo($event['ends_at']);
    }
}
