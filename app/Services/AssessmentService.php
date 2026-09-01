<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentAnswer;
use App\Models\AssessmentQuestion;
use App\Models\AssessmentQuestionOption;
use App\Models\AssessmentSubmission;
use App\Models\Classes;
use App\Models\ClassStaff;
use App\Models\Staff;
use App\Models\Student;
use App\Models\SubjectsEnrollment;
use App\Notifications\AssessmentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AssessmentService
{
    public function create(Staff $tutor, array $data): Assessment
    {
        $class = Classes::findOrFail($data['class_id']);
        $this->ensureTutorAssignedToClass($tutor, $class);

        $questions = $data['questions'] ?? [];
        $totalMarks = collect($questions)->sum(fn ($q) => (float) ($q['marks'] ?? 0));

        $assessment = Assessment::create([
            'class_id' => $class->id,
            'subject_id' => $class->subject_id,
            'created_by' => $tutor->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'pass_mark' => $data['pass_mark'] ?? 50,
            'timer_minutes' => $data['timer_minutes'] ?? null,
            'status' => Assessment::DRAFT,
            'total_marks' => $totalMarks,
        ]);

        $this->storeQuestions($assessment, $questions);

        return $assessment->fresh(['class.subject', 'questions.options']);
    }

    public function update(Staff $tutor, Assessment $assessment, array $data): Assessment
    {
        $this->ensureTutorCanManage($tutor, $assessment);
        $this->ensureDraft($assessment);

        if (array_key_exists('questions', $data)) {
            $assessment->questions()->forceDelete();
            $this->storeQuestions($assessment, $data['questions']);
        }

        $assessment->update([
            'title' => $data['title'] ?? $assessment->title,
            'description' => $data['description'] ?? $assessment->description,
            'instructions' => $data['instructions'] ?? $assessment->instructions,
            'pass_mark' => $data['pass_mark'] ?? $assessment->pass_mark,
            'timer_minutes' => $data['timer_minutes'] ?? $assessment->timer_minutes,
            'total_marks' => $assessment->questions()->sum('marks'),
        ]);

        return $assessment->fresh(['class.subject', 'questions.options']);
    }

    public function publish(Staff $tutor, Assessment $assessment, ?string $opensAt, string $dueAt): Assessment
    {
        $this->ensureTutorCanManage($tutor, $assessment);
        $this->ensureDraft($assessment);

        $assessment->update([
            'status' => Assessment::PUBLISHED,
            'opens_at' => $opensAt ?: now(),
            'due_at' => $dueAt,
        ]);

        $students = SubjectsEnrollment::where('subject_id', $assessment->subject_id)
            ->whereNull('deleted_at')
            ->with('student')
            ->get()
            ->pluck('student')
            ->filter();

        if ($students->isNotEmpty()) {
            Notification::send($students, new AssessmentNotification(
                'assessment_published',
                'New assessment: ' . $assessment->title,
                [
                    'assessment_id' => $assessment->id,
                    'title' => $assessment->title,
                    'due_at' => $assessment->due_at?->toISOString(),
                ]
            ));
        }

        return $assessment->fresh('questions.options');
    }

    public function destroy(Staff $tutor, Assessment $assessment): void
    {
        $this->ensureTutorCanManage($tutor, $assessment);
        $this->ensureDraft($assessment);
        $assessment->delete();
    }

    public function tutorAssessments(Staff $tutor): array
    {
        $classIds = ClassStaff::where('staff_id', $tutor->id)->pluck('class_id');

        $assessments = Assessment::with(['class.subject', 'questions'])
            ->where(function ($q) use ($tutor, $classIds) {
                $q->whereIn('class_id', $classIds)->orWhere('created_by', $tutor->id);
            })
            ->latest()
            ->get();

        return $assessments->map(fn (Assessment $a) => array_merge(
            $a->toArray(),
            ['stats' => $this->aggregateStats($a)]
        ))->all();
    }

    public function studentAssessments(Student $student): array
    {
        $subjectIds = SubjectsEnrollment::where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->pluck('subject_id');

        $classIds = Classes::whereIn('subject_id', $subjectIds)
            ->where('status', 'active')
            ->pluck('id');

        $assessments = Assessment::with('class.subject')
            ->whereIn('class_id', $classIds)
            ->where('status', Assessment::PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('opens_at')->orWhere('opens_at', '<=', now());
            })
            ->latest()
            ->get();

        return $assessments->map(function (Assessment $a) use ($student) {
            return array_merge($a->toArray(), [
                'submission' => $this->submissionFor($student, $a),
            ]);
        })->all();
    }

    public function studentAssessmentDetail(Student $student, Assessment $assessment): array
    {
        $this->ensureStudentEnrolled($student, $assessment);

        if ($assessment->status !== Assessment::PUBLISHED) {
            throw ValidationException::withMessages(['assessment' => 'This assessment is not available.']);
        }

        $submission = $this->submissionFor($student, $assessment);
        $submittedOrGraded = in_array($submission?->status, [AssessmentSubmission::SUBMITTED, AssessmentSubmission::GRADED], true);

        $questions = $assessment->questions->map(function (AssessmentQuestion $q) use ($submittedOrGraded) {
            $payload = $q->only(['id', 'type', 'question', 'marks', 'order', 'explanation']);

            if ($q->type === 'mcq') {
                $payload['options'] = $q->options->map(function (AssessmentQuestionOption $o) use ($submittedOrGraded) {
                    $option = ['id' => $o->id, 'option_text' => $o->option_text];
                    if ($submittedOrGraded) {
                        $option['is_correct'] = $o->is_correct;
                    }
                    return $option;
                });
            } elseif (! $submittedOrGraded) {
                $payload['explanation'] = null;
            }

            return $payload;
        });

        return [
            'assessment' => $assessment->only([
                'id', 'class_id', 'subject_id', 'title', 'description',
                'instructions', 'opens_at', 'due_at', 'status', 'total_marks',
                'pass_mark', 'timer_minutes',
            ]),
            'questions' => $questions->values(),
            'submission' => $submission,
            'answers' => $submission?->answers ?? collect(),
        ];
    }

    public function submit(Student $student, Assessment $assessment, array $payload): AssessmentSubmission
    {
        $this->ensureStudentEnrolled($student, $assessment);

        if ($assessment->status !== Assessment::PUBLISHED) {
            throw ValidationException::withMessages(['assessment' => 'This assessment is not open for submission.']);
        }

        if ($assessment->opens_at && $assessment->opens_at->isFuture()) {
            throw ValidationException::withMessages(['assessment' => 'This assessment has not opened yet.']);
        }

        if ($assessment->due_at && $assessment->due_at->isPast()) {
            throw ValidationException::withMessages(['assessment' => 'This assessment is past its due date.']);
        }

        return DB::transaction(function () use ($student, $assessment, $payload) {
            $submission = AssessmentSubmission::firstOrCreate(
                ['assessment_id' => $assessment->id, 'student_id' => $student->id],
                ['total_marks' => $assessment->total_marks]
            );

            if (in_array($submission->status, [AssessmentSubmission::SUBMITTED, AssessmentSubmission::GRADED], true)) {
                throw ValidationException::withMessages(['assessment' => 'You have already submitted this assessment.']);
            }

            $score = 0.0;

            foreach ($assessment->questions as $question) {
                $given = $payload['answers'][$question->id] ?? null;

                if ($question->type === 'mcq') {
                    $option = null;
                    $givenOptionId = $given['question_option_id'] ?? null;

                    if ($givenOptionId) {
                        $option = AssessmentQuestionOption::where('question_id', $question->id)->find($givenOptionId);
                    }

                    $correct = $option?->is_correct ?? false;
                    $marks = $correct ? (float) $question->marks : 0.0;
                    if ($correct) {
                        $score += $marks;
                    }

                    AssessmentAnswer::updateOrCreate(
                        ['submission_id' => $submission->id, 'question_id' => $question->id],
                        [
                            'question_option_id' => $givenOptionId,
                            'answer' => null,
                            'is_correct' => $correct,
                            'marks_awarded' => $marks,
                            'feedback' => null,
                        ]
                    );
                } else {
                    AssessmentAnswer::updateOrCreate(
                        ['submission_id' => $submission->id, 'question_id' => $question->id],
                        [
                            'question_option_id' => null,
                            'answer' => $given['answer'] ?? null,
                            'is_correct' => null,
                            'marks_awarded' => null,
                            'feedback' => null,
                        ]
                    );
                }
            }

            $submission->update([
                'status' => AssessmentSubmission::SUBMITTED,
                'submitted_at' => now(),
                'score' => $score,
                'total_marks' => $assessment->total_marks,
                'percentage' => $assessment->total_marks > 0 ? round($score / $assessment->total_marks * 100, 2) : 0,
            ]);

            if ($assessment->creator) {
                Notification::send($assessment->creator, new AssessmentNotification(
                    'assessment_submitted',
                    $student->firstname . ' ' . $student->surname . ' submitted ' . $assessment->title,
                    ['assessment_id' => $assessment->id, 'submission_id' => $submission->id]
                ));
            }

            return $submission->fresh('answers');
        });
    }

    public function tutorSubmissions(Staff $tutor, Assessment $assessment): array
    {
        $this->ensureTutorCanManage($tutor, $assessment);

        return $assessment->submissions()
            ->with('student')
            ->latest()
            ->get()
            ->map(fn (AssessmentSubmission $s) => array_merge(
                $s->toArray(),
                ['questions_answered' => $s->answers()->whereNotNull('marks_awarded')->count()]
            ))
            ->all();
    }

    public function submissionDetail(Staff $tutor, AssessmentSubmission $submission): AssessmentSubmission
    {
        $this->ensureTutorCanManage($tutor, $submission->assessment);
        return $submission->load(['student', 'answers.question', 'answers.option']);
    }

    public function grade(Staff $tutor, AssessmentSubmission $submission, array $payload): AssessmentSubmission
    {
        $this->ensureTutorCanManage($tutor, $submission->assessment);

        if (! in_array($submission->status, [AssessmentSubmission::SUBMITTED, AssessmentSubmission::GRADED], true)) {
            throw ValidationException::withMessages(['submission' => 'This submission cannot be graded.']);
        }

        return DB::transaction(function () use ($tutor, $submission, $payload) {
            $submission->load('answers.question');
            $score = 0.0;

            foreach ($submission->answers as $answer) {
                $grade = $payload['grades'][$answer->question_id] ?? null;

                if (! $grade) {
                    if ($answer->marks_awarded !== null) {
                        $score += (float) $answer->marks_awarded;
                    }
                    continue;
                }

                $marks = isset($grade['marks_awarded'])
                    ? (float) $grade['marks_awarded']
                    : (float) ($answer->marks_awarded ?? 0);

                $isCorrect = array_key_exists('is_correct', $grade)
                    ? (bool) $grade['is_correct']
                    : $answer->is_correct;

                $answer->update([
                    'marks_awarded' => $marks,
                    'feedback' => $grade['feedback'] ?? null,
                    'is_correct' => $isCorrect,
                ]);

                $score += $marks;
            }

            $submission->update([
                'status' => AssessmentSubmission::GRADED,
                'graded_by' => $tutor->id,
                'graded_at' => now(),
                'score' => $score,
                'percentage' => $submission->total_marks > 0 ? round($score / $submission->total_marks * 100, 2) : 0,
            ]);

            if ($submission->student) {
                Notification::send($submission->student, new AssessmentNotification(
                    'assessment_graded',
                    'Your assessment "' . $submission->assessment->title . '" has been graded.',
                    [
                        'assessment_id' => $submission->assessment_id,
                        'title' => $submission->assessment->title,
                        'submission_id' => $submission->id,
                        'score' => $score,
                        'total_marks' => $submission->total_marks,
                        'percentage' => $submission->percentage,
                    ]
                ));
            }

            return $submission->fresh(['answers.question', 'answers.option', 'student']);
        });
    }

    public function reopen(Staff $tutor, AssessmentSubmission $submission): AssessmentSubmission
    {
        $this->ensureTutorCanManage($tutor, $submission->assessment);

        return DB::transaction(function () use ($submission) {
            $submission->answers()->delete();
            $submission->update([
                'status' => AssessmentSubmission::IN_PROGRESS,
                'submitted_at' => null,
                'score' => 0,
                'percentage' => null,
                'graded_by' => null,
                'graded_at' => null,
            ]);

            return $submission->fresh(['answers', 'student']);
        });
    }

    public function aggregateList(): array
    {
        return Assessment::with(['class.subject', 'creator'])
            ->latest()
            ->get()
            ->map(fn (Assessment $a) => array_merge(
                $a->toArray(),
                ['stats' => $this->aggregateStats($a)]
            ))
            ->all();
    }

    public function aggregateStats(Assessment $assessment): array
    {
        $enrolledIds = SubjectsEnrollment::where('subject_id', $assessment->subject_id)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('student_id');

        $submissions = AssessmentSubmission::where('assessment_id', $assessment->id)
            ->whereNull('deleted_at')
            ->get();

        $graded = $submissions->where('status', AssessmentSubmission::GRADED);
        $submitted = $submissions->where('status', AssessmentSubmission::SUBMITTED);
        $absent = $submissions->where('status', AssessmentSubmission::ABSENT);
        $inProgress = $submissions->where('status', AssessmentSubmission::IN_PROGRESS);

        $submittedStudentIds = $submissions->pluck('student_id')->toArray();
        $notStarted = $enrolledIds->reject(fn ($id) => in_array($id, $submittedStudentIds, true));

        $avgPercentage = $graded->avg('percentage');
        $passMark = (float) $assessment->pass_mark;
        $passed = $graded->filter(fn ($s) => $s->percentage !== null && (float) $s->percentage >= $passMark)->count();

        return [
            'total_students' => $enrolledIds->count(),
            'submitted_count' => $submitted->count() + $graded->count(),
            'graded_count' => $graded->count(),
            'in_progress_count' => $inProgress->count(),
            'absent_count' => $absent->count(),
            'not_started_count' => $notStarted->count(),
            'average_percentage' => $avgPercentage === null ? null : round((float) $avgPercentage, 2),
            'pass_rate' => $graded->isEmpty() ? null : round($passed / $graded->count() * 100, 2),
            'pass_mark' => $passMark,
        ];
    }

    public function markUnattended(): void
    {
        Assessment::where('status', Assessment::PUBLISHED)
            ->where('due_at', '<', now())
            ->chunkById(100, function ($assessments) {
                foreach ($assessments as $assessment) {
                    $this->closeAndMarkAbsent($assessment);
                }
            });
    }

    private function closeAndMarkAbsent(Assessment $assessment): void
    {
        DB::transaction(function () use ($assessment) {
            $locked = Assessment::lockForUpdate()->find($assessment->id);
            if (! $locked || $locked->status !== Assessment::PUBLISHED) {
                return;
            }

            $locked->update(['status' => Assessment::CLOSED]);

            $enrolledIds = SubjectsEnrollment::where('subject_id', $locked->subject_id)
                ->whereNull('deleted_at')
                ->distinct()
                ->pluck('student_id');

            $existing = AssessmentSubmission::where('assessment_id', $locked->id)
                ->whereIn('student_id', $enrolledIds)
                ->get()
                ->keyBy('student_id');

            foreach ($existing as $submission) {
                if ($submission->status === AssessmentSubmission::IN_PROGRESS) {
                    $submission->update(['status' => AssessmentSubmission::ABSENT]);
                }
            }

            $missing = $enrolledIds->reject(fn ($id) => $existing->has($id));

            foreach ($missing as $studentId) {
                AssessmentSubmission::create([
                    'assessment_id' => $locked->id,
                    'student_id' => $studentId,
                    'status' => AssessmentSubmission::ABSENT,
                    'total_marks' => $locked->total_marks,
                ]);
            }
        });
    }

    private function storeQuestions(Assessment $assessment, array $questions): void
    {
        foreach (array_values($questions) as $index => $qdata) {
            $question = AssessmentQuestion::create([
                'assessment_id' => $assessment->id,
                'type' => $qdata['type'],
                'question' => $qdata['question'],
                'marks' => $qdata['marks'] ?? 1,
                'order' => $index,
                'explanation' => $qdata['explanation'] ?? null,
            ]);

            if ($question->type === 'mcq' && isset($qdata['options'])) {
                $correctCount = collect($qdata['options'])->filter(fn ($o) => ! empty($o['is_correct']))->count();

                if ($correctCount !== 1) {
                    throw ValidationException::withMessages([
                        "questions.{$index}.options" => 'Each MCQ must have exactly one correct option.',
                    ]);
                }

                foreach ($qdata['options'] as $option) {
                    AssessmentQuestionOption::create([
                        'question_id' => $question->id,
                        'option_text' => $option['option_text'],
                        'is_correct' => (bool) ($option['is_correct'] ?? false),
                    ]);
                }
            }
        }
    }

    private function ensureDraft(Assessment $assessment): void
    {
        if ($assessment->status !== Assessment::DRAFT) {
            throw ValidationException::withMessages(['assessment' => 'Only draft assessments can be edited or deleted.']);
        }
    }

    private function ensureTutorAssignedToClass(Staff $tutor, Classes $class): void
    {
        $assigned = ClassStaff::where('class_id', $class->id)
            ->where('staff_id', $tutor->id)
            ->exists();

        if (! $assigned) {
            throw ValidationException::withMessages(['class_id' => 'You are not assigned to this class.']);
        }
    }

    private function ensureTutorCanManage(Staff $tutor, Assessment $assessment): void
    {
        $assigned = ClassStaff::where('class_id', $assessment->class_id)
            ->where('staff_id', $tutor->id)
            ->exists();

        if (! $assigned && $assessment->created_by !== $tutor->id) {
            throw ValidationException::withMessages(['assessment' => 'You do not have permission to manage this assessment.']);
        }
    }

    private function ensureStudentEnrolled(Student $student, Assessment $assessment): void
    {
        $enrolled = SubjectsEnrollment::where('subject_id', $assessment->subject_id)
            ->where('student_id', $student->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $enrolled) {
            throw ValidationException::withMessages(['assessment' => 'You are not enrolled in this assessment.']);
        }
    }

    private function submissionFor(Student $student, Assessment $assessment): ?AssessmentSubmission
    {
        return AssessmentSubmission::where('assessment_id', $assessment->id)
            ->where('student_id', $student->id)
            ->with('answers')
            ->first();
    }
}
