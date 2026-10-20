<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\QuizSubmission;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class QuizService
{
    /*
    |--------------------------------------------------------------------------
    | Staff / Admin — Quiz Management
    |--------------------------------------------------------------------------
    */

    /**
     * List all quizzes for a lesson.
     */
    public function listByLesson(Lesson $lesson): Collection
    {
        return $lesson->quizzes()
            ->withCount('questions')
            ->get();
    }

    /**
     * Create a quiz with its questions and options.
     *
     * Each question in $data['questions'] may have:
     *   type, question, marks, order, explanation, image, options[]
     */
    public function create($staff, Lesson $lesson, array $data): Quiz
    {
        $quiz = $lesson->quizzes()->create([
            'created_by'                    => $staff->id,
            'title'                         => $data['title'],
            'description'                   => $data['description'] ?? null,
            'instructions'                  => $data['instructions'] ?? null,
            'pass_mark'                     => $data['pass_mark'] ?? 50,
            'timer_minutes'                 => $data['timer_minutes'] ?? null,
            'max_attempts'                  => $data['max_attempts'] ?? null,
            'show_answers_after_submission' => $data['show_answers_after_submission'] ?? true,
            'status'                        => Quiz::DRAFT,
        ]);

        $this->syncQuestions($quiz, $data['questions'] ?? []);

        return $quiz->load('questions.options');
    }

    /**
     * Update a quiz and optionally replace its questions.
     */
    public function update($staff, Quiz $quiz, array $data): Quiz
    {
        $quiz->update([
            'title'                         => $data['title'] ?? $quiz->title,
            'description'                   => $data['description'] ?? $quiz->description,
            'instructions'                  => $data['instructions'] ?? $quiz->instructions,
            'pass_mark'                     => $data['pass_mark'] ?? $quiz->pass_mark,
            'timer_minutes'                 => $data['timer_minutes'] ?? $quiz->timer_minutes,
            'max_attempts'                  => $data['max_attempts'] ?? $quiz->max_attempts,
            'show_answers_after_submission' => $data['show_answers_after_submission'] ?? $quiz->show_answers_after_submission,
        ]);

        if (isset($data['questions'])) {
            // Drop old questions and re-create to keep things clean
            $quiz->questions()->delete();
            $this->syncQuestions($quiz, $data['questions']);
        }

        return $quiz->fresh()->load('questions.options');
    }

    /**
     * Publish a quiz (students can now attempt it).
     */
    public function publish($staff, Quiz $quiz): Quiz
    {
        throw_if(
            $quiz->questions()->count() === 0,
            ValidationException::withMessages(['quiz' => 'Cannot publish a quiz with no questions.'])
        );

        $quiz->update(['status' => Quiz::PUBLISHED]);

        // Recalculate total_marks from questions
        $quiz->update(['total_marks' => $quiz->questions()->sum('marks')]);

        return $quiz->fresh();
    }

    /**
     * Archive a quiz.
     */
    public function archive($staff, Quiz $quiz): Quiz
    {
        $quiz->update(['status' => Quiz::ARCHIVED]);
        return $quiz->fresh();
    }

    /**
     * Soft-delete a quiz.
     */
    public function destroy($staff, Quiz $quiz): void
    {
        $quiz->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Student — Quiz Attempts
    |--------------------------------------------------------------------------
    */

    /**
     * Show a quiz to a student (hide correct options until submission).
     */
    public function studentShow(Quiz $quiz, Student $student): array
    {
        $submission = QuizSubmission::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->latest()
            ->first();

        $questions = $quiz->questions->map(function (QuizQuestion $q) {
            return [
                'id'          => $q->id,
                'type'        => $q->type,
                'question'    => $q->question,
                'marks'       => $q->marks,
                'order'       => $q->order,
                'image_url'   => $q->image_url,
                // Hide is_correct from options until submitted
                'options'     => $q->options->map(fn($o) => [
                    'id'          => $o->id,
                    'option_text' => $o->option_text,
                    'order'       => $o->order,
                ]),
            ];
        });

        return [
            'quiz'            => $quiz->only(['id', 'title', 'description', 'instructions', 'total_marks', 'pass_mark', 'timer_minutes', 'max_attempts']),
            'questions'       => $questions,
            'latest_attempt'  => $submission,
        ];
    }

    /**
     * Start a new quiz attempt for a student.
     */
    public function startAttempt(Quiz $quiz, Student $student): QuizSubmission
    {
        // Check max_attempts
        if ($quiz->max_attempts !== null) {
            $attempts = QuizSubmission::where('quiz_id', $quiz->id)
                ->where('student_id', $student->id)
                ->whereIn('status', [QuizSubmission::SUBMITTED, QuizSubmission::GRADED])
                ->count();

            throw_if(
                $attempts >= $quiz->max_attempts,
                ValidationException::withMessages(['quiz' => 'You have reached the maximum number of attempts for this quiz.'])
            );
        }

        $attemptNumber = QuizSubmission::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->count() + 1;

        return QuizSubmission::create([
            'quiz_id'        => $quiz->id,
            'student_id'     => $student->id,
            'attempt_number' => $attemptNumber,
            'status'         => QuizSubmission::IN_PROGRESS,
            'started_at'     => now(),
            'total_marks'    => $quiz->total_marks,
        ]);
    }

    /**
     * Submit a student's answers for a quiz attempt, auto-grade MCQ and T/F.
     */
    public function submit(Quiz $quiz, QuizSubmission $submission, Student $student, array $data): QuizSubmission
    {
        throw_if(
            $submission->status !== QuizSubmission::IN_PROGRESS,
            ValidationException::withMessages(['submission' => 'This attempt has already been submitted.'])
        );

        throw_if(
            $submission->student_id !== $student->id,
            ValidationException::withMessages(['submission' => 'Unauthorized.'])
        );

        $totalScore = 0;

        foreach ($data['answers'] as $answerData) {
            $question = QuizQuestion::findOrFail($answerData['question_id']);
            $isCorrect = null;
            $marksAwarded = 0;
            $selectedOptionId = $answerData['selected_option_id'] ?? null;

            if (in_array($question->type, [QuizQuestion::TYPE_MULTIPLE_CHOICE, QuizQuestion::TYPE_TRUE_FALSE]) && $selectedOptionId) {
                $option = $question->options()->find($selectedOptionId);
                $isCorrect = $option?->is_correct ?? false;
                $marksAwarded = $isCorrect ? $question->marks : 0;
            }

            QuizAnswer::create([
                'submission_id'      => $submission->id,
                'question_id'        => $question->id,
                'selected_option_id' => $selectedOptionId,
                'text_answer'        => $answerData['text_answer'] ?? null,
                'is_correct'         => $isCorrect,
                'marks_awarded'      => $marksAwarded,
            ]);

            $totalScore += $marksAwarded;
        }

        $percentage = $quiz->total_marks > 0 ? round(($totalScore / $quiz->total_marks) * 100, 2) : 0;
        $passed = $percentage >= $quiz->pass_mark;

        $submission->update([
            'status'       => QuizSubmission::SUBMITTED,
            'submitted_at' => now(),
            'score'        => $totalScore,
            'percentage'   => $percentage,
            'passed'       => $passed,
        ]);

        return $submission->fresh()->load('answers.question', 'answers.selectedOption');
    }

    /**
     * Return submission results with correct answers shown (if allowed by quiz settings).
     */
    public function submissionResult(Quiz $quiz, QuizSubmission $submission): array
    {
        $result = [
            'submission' => $submission->load('answers.question.options', 'answers.selectedOption'),
        ];

        if ($quiz->show_answers_after_submission) {
            $result['correct_answers'] = $quiz->questions->mapWithKeys(fn($q) => [
                $q->id => $q->options->where('is_correct', true)->values(),
            ]);
        }

        return $result;
    }

    /**
     * List all student submissions for a quiz (tutor / admin view).
     */
    public function tutorSubmissions(Quiz $quiz): Collection
    {
        return $quiz->submissions()
            ->with('student')
            ->orderByDesc('created_at')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function syncQuestions(Quiz $quiz, array $questions): void
    {
        foreach ($questions as $index => $qData) {
            $question = $quiz->questions()->create([
                'type'        => $qData['type'],
                'question'    => $qData['question'],
                'marks'       => $qData['marks'] ?? 1,
                'order'       => $qData['order'] ?? ($index + 1),
                'explanation' => $qData['explanation'] ?? null,
                'image'       => $qData['image'] ?? null,
            ]);

            if (!empty($qData['options'])) {
                foreach ($qData['options'] as $oIdx => $optData) {
                    $question->options()->create([
                        'option_text' => $optData['option_text'],
                        'is_correct'  => $optData['is_correct'] ?? false,
                        'order'       => $optData['order'] ?? ($oIdx + 1),
                    ]);
                }
            }
        }

        // Update total_marks
        $quiz->update(['total_marks' => $quiz->questions()->sum('marks')]);
    }
}
