<?php

namespace Database\Seeders;

use App\Models\ClassAttendance;
use App\Models\Classes;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CoursesEnrollment;
use App\Models\ExamAttempt;
use App\Models\ExamBody;
use App\Models\ExamYear;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class GuardianDashboardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $course = Course::withTrashed()->updateOrCreate(
                ['slug' => 'guardian-dashboard-test-course'],
                [
                    'title' => 'Guardian Dashboard Test Course',
                    'description' => 'Local course used to test the guardian dashboard API.',
                    'banner' => 'courses/guardian-dashboard-test.png',
                    'status' => 'active',
                    'price' => 8000,
                ]
            );
            $course->restore();

            $subject = Subject::withTrashed()->updateOrCreate(
                ['name' => 'Guardian Dashboard Mathematics'],
                [
                    'description' => 'Local subject used to test guardian performance data.',
                    'banner' => 'subjects/guardian-dashboard-mathematics.png',
                    'departments' => ['science'],
                    'status' => 'active',
                ]
            );
            $subject->restore();

            $course->subjects()->syncWithoutDetaching([$subject->id]);

            $guardian = Guardian::withTrashed()->updateOrCreate(
                ['email' => 'guardian.dashboard@example.com'],
                [
                    'firstname' => 'Test',
                    'surname' => 'Guardian',
                    'password' => Hash::make('Password123!'),
                    'gender' => 'female',
                    'email_verified_at' => now(),
                    'location' => 'Lagos, Nigeria',
                    'address' => 'Local testing address',
                ]
            );
            $guardian->restore();

            $student = Student::withTrashed()->updateOrCreate(
                ['email' => 'ward.dashboard@example.com'],
                [
                    'firstname' => 'Test',
                    'surname' => 'Ward',
                    'password' => Hash::make('Password123!'),
                    'gender' => 'male',
                    'date_of_birth' => '2010-05-10',
                    'email_verified_at' => now(),
                    'location' => 'Lagos, Nigeria',
                    'address' => 'Local testing address',
                    'department' => 'science',
                ]
            );
            $student->restore();

            DB::table('guardian_students')->updateOrInsert(
                [
                    'guardian_id' => $guardian->id,
                    'student_id' => $student->id,
                ],
                [
                    'relationship' => 'parent',
                    'deleted_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $enrollment = CoursesEnrollment::withTrashed()->updateOrCreate(
                [
                    'student_id' => $student->id,
                    'course_id' => $course->id,
                ],
                [
                    'start_date' => now()->subMonth(),
                    'end_date' => now()->addMonths(2),
                    'billing_cycle' => 'monthly',
                    'cost' => 8000,
                    'status' => 'active',
                ]
            );
            $enrollment->restore();

            $payment = Payment::withTrashed()->updateOrCreate(
                ['gateway_reference' => 'guardian-dashboard-local-payment'],
                [
                    'student_id' => $student->id,
                    'course_enrollment_id' => $enrollment->id,
                    'amount' => 8000,
                    'currency' => 'NGN',
                    'payment_method' => 'manual',
                    'gateway' => 'local-test',
                    'status' => 'successful',
                    'billing_cycle' => 'monthly',
                    'paid_at' => now(),
                ]
            );
            $payment->restore();

            $examBody = ExamBody::withTrashed()->updateOrCreate(
                ['slug' => 'guardian-dashboard-test-exam'],
                [
                    'name' => 'Guardian Dashboard Test Exam',
                    'course_id' => $course->id,
                    'status' => 'active',
                ]
            );
            $examBody->restore();

            $examYear = ExamYear::withTrashed()->updateOrCreate(
                [
                    'exam_body_id' => $examBody->id,
                    'subject_id' => $subject->id,
                    'year' => 2026,
                ],
                ['status' => 'active']
            );
            $examYear->restore();

            ExamAttempt::where('student_id', $student->id)
                ->where('exam_year_id', $examYear->id)
                ->delete();

            foreach ([42, 76, 91] as $percentage) {
                ExamAttempt::create([
                    'student_id' => $student->id,
                    'exam_year_id' => $examYear->id,
                    'score' => $percentage,
                    'total_questions' => 100,
                    'correct_answers' => $percentage,
                    'wrong_answers' => 100 - $percentage,
                    'unanswered' => 0,
                    'percentage' => $percentage,
                    'started_at' => now()->subMinutes(45),
                    'submitted_at' => now()->subMinutes(15),
                    'status' => ExamAttempt::COMPLETED,
                ]);
            }

            $class = Classes::withTrashed()->updateOrCreate(
                [
                    'subject_id' => $subject->id,
                    'title' => 'Guardian Dashboard Test Live Class',
                ],
                [
                    'description' => 'Local live class used to test attendance.',
                    'status' => 'active',
                ]
            );
            $class->restore();

            $schedule = ClassSchedule::withTrashed()->updateOrCreate(
                [
                    'class_id' => $class->id,
                    'day_of_week' => 'monday',
                    'start_time' => '10:00:00',
                    'end_time' => '11:00:00',
                ],
                [
                    'start_date' => now()->subMonth()->toDateString(),
                    'end_date' => now()->addMonth()->toDateString(),
                ]
            );
            $schedule->restore();

            $session = ClassSession::withTrashed()->updateOrCreate(
                [
                    'class_id' => $class->id,
                    'class_schedule_id' => $schedule->id,
                    'session_date' => now()->subDay()->toDateString(),
                ],
                [
                    'starts_at' => '10:00:00',
                    'ends_at' => '11:00:00',
                    'class_link' => 'https://example.test/local-class',
                    'status' => 'completed',
                ]
            );
            $session->restore();

            $attendance = ClassAttendance::withTrashed()->updateOrCreate(
                [
                    'class_session_id' => $session->id,
                    'student_id' => $student->id,
                ],
                [
                    'joined_at' => now()->subDay()->setTime(10, 5),
                    'left_at' => now()->subDay()->setTime(10, 55),
                    'attendance_duration' => now()->subDay()->setTime(0, 50),
                    'status' => 'present',
                ]
            );
            $attendance->restore();

            $this->command?->info('Guardian dashboard test data seeded.');
            $this->command?->line('Login: guardian.dashboard@example.com');
            $this->command?->line('Password: Password123!');
            $this->command?->line("Ward ID: {$student->id}");
        });
    }
}
