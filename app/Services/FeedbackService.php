<?php

namespace App\Services;

use Exception;
use App\Models\Staff;
use App\Models\Course;
use App\Models\Subject;
use App\Models\Classes;
use App\Models\Feedback;
use App\Models\ExamAttempt;
use Illuminate\Database\Eloquent\Model;

class FeedbackService
{
    /**
     * Models that can receive feedback.
     */
    protected array $feedbackables = [

        'course' => Course::class,

        'subject' => Subject::class,

        'class' => Classes::class,

        'class_session' => \App\Models\ClassSession::class,

        'staff' => Staff::class,

        'exam_attempt' => ExamAttempt::class,

    ];

    /**
     * Resolve the feedbackable model.
     */
    protected function resolveFeedbackable(
        string $type,
        int $id
    ): Model {

        if (!isset($this->feedbackables[$type])) {
            throw new Exception(
                'Invalid feedback type.'
            );
        }

        $model = $this->feedbackables[$type]::find($id);

        if (!$model) {
            throw new Exception(
                'The requested resource was not found.'
            );
        }

        return $model;
    }

    /**
     * Create feedback.
     */
    public function create(
        Model $feedbacker,
        array $data
    ): Feedback {

        $feedbackable = $this->resolveFeedbackable(
            $data['feedbackable_type'],
            $data['feedbackable_id']
        );

        $exists = Feedback::where([
            'feedbacker_type' => get_class($feedbacker),
            'feedbacker_id' => $feedbacker->id,
            'feedbackable_type' => get_class($feedbackable),
            'feedbackable_id' => $feedbackable->id,
        ])->exists();

        if ($exists) {
            throw new Exception(
                'You have already submitted feedback.'
            );
        }

        return Feedback::create([

            'feedbacker_type' => get_class($feedbacker),

            'feedbacker_id' => $feedbacker->id,

            'feedbackable_type' => get_class($feedbackable),

            'feedbackable_id' => $feedbackable->id,

            'rating' => $data['rating'],

            'title' => $data['title'] ?? null,

            'comment' => $data['comment'] ?? null,

            'ratings' => $data['ratings'] ?? null,

            'would_recommend' => $data['would_recommend'] ?? true,

            'is_anonymous' => $data['is_anonymous'] ?? false,

            'status' => 'published',

        ]);
    }

    /**
     * Update feedback.
     */
    public function update(
        Feedback $feedback,
        array $data
    ): Feedback {

        $feedback->update([

            'rating' => $data['rating'] ?? $feedback->rating,

            'title' => $data['title'] ?? $feedback->title,

            'comment' => $data['comment'] ?? $feedback->comment,

            'ratings' => $data['ratings'] ?? $feedback->ratings,

            'would_recommend' => $data['would_recommend']
                ?? $feedback->would_recommend,

            'is_anonymous' => $data['is_anonymous']
                ?? $feedback->is_anonymous,

        ]);

        return $feedback->fresh();
    }

    /**
     * Delete feedback.
     */
    public function delete(
        Feedback $feedback
    ): bool {

        return (bool) $feedback->delete();
    }

    /**
     * Average rating.
     */
    public function averageRating(
        Model $feedbackable
    ): float {

        return round(

            $feedbackable
                ->feedbacks()
                ->published()
                ->avg('rating') ?? 0,

            2

        );
    }

    /**
     * Total feedback.
     */
    public function totalFeedback(
        Model $feedbackable
    ): int {

        return $feedbackable
            ->feedbacks()
            ->published()
            ->count();
    }

    /**
     * Recommendation percentage.
     */
    public function recommendationPercentage(
        Model $feedbackable
    ): float {

        $query = $feedbackable
            ->feedbacks()
            ->published();

        $total = $query->count();

        if ($total === 0) {
            return 0;
        }

        $recommended = $query
            ->where(
                'would_recommend',
                true
            )
            ->count();

        return round(
            ($recommended / $total) * 100,
            2
        );
    }

    /**
     * Rating breakdown.
     */
    public function ratingDistribution(
        Model $feedbackable
    ): array {

        return collect(range(1, 5))
            ->mapWithKeys(function ($rating) use ($feedbackable) {

                return [

                    $rating => $feedbackable
                        ->feedbacks()
                        ->published()
                        ->where('rating', $rating)
                        ->count()

                ];
            })
            ->toArray();
    }
}
