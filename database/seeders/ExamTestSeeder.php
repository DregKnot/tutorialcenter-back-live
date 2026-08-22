<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CoursesEnrollment;
use App\Models\ExamBody;
use App\Models\ExamYear;
use App\Models\PastQuestion;
use App\Models\PastQuestionOption;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExamTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $studentId = env('TEST_STUDENT_ID');
        $student = $studentId
            ? Student::find($studentId)
            : Student::query()->first();

        if (! $student) {
            $this->command?->error(
                'No student found. Create a student first or set TEST_STUDENT_ID in .env.'
            );

            return;
        }

        $course = Course::query()->where('title', 'JAMB')->first()
            ?? Course::query()->first();
        $subject = Subject::query()->where('name', 'Mathematics')->first()
            ?? Subject::query()->first();

        if (! $course || ! $subject) {
            $this->command?->error(
                'Course and subject data are required. Run CourseSeeder and SubjectSeeder first.'
            );

            return;
        }

        DB::transaction(function () use ($student, $course, $subject) {
            $courseEnrollment = CoursesEnrollment::updateOrCreate(
                [
                    'student_id' => $student->id,
                    'course_id' => $course->id,
                ],
                [
                    'start_date' => now()->startOfDay(),
                    'end_date' => now()->addYear(),
                    'billing_cycle' => 'annual',
                    'cost' => $course->price ?? 0,
                    'status' => 'active',
                ]
            );

            DB::table('payments')->updateOrInsert(
                [
                    'course_enrollment_id' => $courseEnrollment->id,
                    'student_id' => $student->id,
                    'gateway_reference' => 'exam-test-payment-'.$student->id.'-'.$course->id,
                ],
                [
                    'amount' => $course->price ?? 0,
                    'currency' => 'NGN',
                    'payment_method' => 'manual',
                    'gateway' => 'test',
                    'status' => 'successful',
                    'billing_cycle' => 'annual',
                    'meta' => json_encode(['source' => 'ExamTestSeeder']),
                    'paid_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('subjects_enrollments')->updateOrInsert(
                [
                    'course_enrollment_id' => $courseEnrollment->id,
                    'subject_id' => $subject->id,
                    'student_id' => $student->id,
                ],
                [
                    'progress' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $examBody = ExamBody::updateOrCreate(
                ['slug' => 'test-jamb'],
                [
                    'name' => 'Test JAMB',
                    'course_id' => $course->id,
                    'status' => 'active',
                ]
            );

            $examYear = ExamYear::updateOrCreate(
                [
                    'exam_body_id' => $examBody->id,
                    'subject_id' => $subject->id,
                    'year' => 2026,
                ],
                ['status' => 'active']
            );

            for ($number = 1; $number <= 5; $number++) {
                $question = PastQuestion::updateOrCreate(
                    [
                        'exam_year_id' => $examYear->id,
                        'question_number' => $number,
                    ],
                    [
                        'question' => "Test question {$number}: What is {$number} + {$number}?",
                        'question_type' => 'multiple_choice',
                        'marks' => 1,
                        'explanation' => 'This is a seeded test question.',
                        'status' => 'active',
                    ]
                );

                foreach ([
                    ['A', (string) ($number * 2), true],
                    ['B', (string) ($number * 2 + 1), false],
                    ['C', (string) ($number * 2 + 2), false],
                    ['D', (string) ($number * 2 + 3), false],
                ] as [$label, $text, $correct]) {
                    PastQuestionOption::updateOrCreate(
                        [
                            'past_question_id' => $question->id,
                            'label' => $label,
                        ],
                        [
                            'option_text' => $text,
                            'is_correct' => $correct,
                            'sort_order' => ord($label) - ord('A'),
                        ]
                    );
                }
            }
        });

        $this->command?->info(
            "Exam test data seeded for student {$student->id}, course {$course->id}, subject {$subject->id}."
        );
    }
}
