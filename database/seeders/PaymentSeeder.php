<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CoursesEnrollment;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('PaymentSeeder must not be run in production.');
        }

        DB::transaction(function () {
            // $admin = Staff::updateOrCreate(
            //     ['email' => 'payment-api-admin@example.com'],
            //     [
            //         'staff_id' => 'TCAPI00001',
            //         'firstname' => 'Payment',
            //         'middlename' => null,
            //         'surname' => 'API Admin',
            //         'tel' => '08000000003',
            //         'password' => Hash::make('AdminPassword123!'),
            //         'gender' => 'others',
            //         'profile_picture' => 'test-admin-profile.png',
            //         'date_of_birth' => '1990-01-01',
            //         'email_verified_at' => now(),
            //         'tel_verified_at' => now(),
            //         'location' => 'Lagos, Nigeria',
            //         'address' => 'API test address',
            //         'role' => 'admin',
            //         'inducted_by' => null,
            //     ]
            // );

            // $course = Course::updateOrCreate(
            //     ['slug' => 'api-payment-test-course'],
            //     [
            //         'title' => 'API Payment Test Course',
            //         'description' => 'Test course for registration recovery and complimentary enrollment APIs.',
            //         'banner' => 'test-course-banner.png',
            //         'status' => 'active',
            //         'price' => 10000,
            //     ]
            // );

            $courses = collect([
                [
                    'slug' => 'WAEC',
                    'title' => 'WAEC Test Course',
                    'description' => 'Test course for registration recovery and complimentary enrollment APIs.',
                    'banner' => 'test-course-banner.png',
                    'price' => 10000,
                ],
                [
                    'slug' => 'JAMB',
                    'title' => 'JAMB Test Course',
                    'description' => 'Test course for registration recovery and complimentary enrollment APIs.',
                    'banner' => 'test-course-banner.png',
                    'price' => 20000,
                ],
                [
                    'slug' => 'GCE',
                    'title' => 'GCE Test Course',
                    'description' => 'Test course for registration recovery and complimentary enrollment APIs.',
                    'banner' => 'test-course-banner.png',
                    'price' => 15000,
                ],
                [
                    'slug' => 'NECO',
                    'title' => 'NECO Test Course',
                    'description' => 'Test course for registration recovery and complimentary enrollment APIs.',
                    'banner' => 'test-course-banner.png',
                    'price' => 8000,
                ],
            ])->map(function (array $data) {
                return Course::updateOrCreate(
                    ['slug' => $data['slug']],
                    $data
                );
            });

            $subjectIds = collect([
                [
                    'name' => 'API Test Mathematics',
                    'description' => 'Test subject for payment API verification.',
                    'banner' => 'test-mathematics-banner.png',
                    'departments' => ['science'],
                    'status' => 'active',
                ],
                [
                    'name' => 'API Test English',
                    'description' => 'Test subject for payment API verification.',
                    'banner' => 'test-english-banner.png',
                    'departments' => ['general'],
                    'status' => 'active',
                ],
            ])->map(function (array $data) {
                return Subject::updateOrCreate(
                    ['name' => $data['name']],
                    $data
                )->id;
            });

            $courses->each(function (Course $course) use ($subjectIds) {
                $course->subjects()->syncWithoutDetaching($subjectIds->all());
            });

            $recoveryStudent = Student::updateOrCreate(
                ['email' => 'payment-recovery-test@example.com'],
                [
                    'firstname' => 'Payment',
                    'surname' => 'Recovery Test',
                    'tel' => '08000000001',
                    'password' => Hash::make('Password123!'),
                    'gender' => 'male',
                    'email_verified_at' => now(),
                    'location' => 'Lagos, Nigeria',
                    'address' => 'API test address',
                    'department' => 'science',
                ]
            );

            $recoveryEnrollments = $courses->map(function (Course $course) use ($recoveryStudent) {
                return CoursesEnrollment::updateOrCreate(
                    [
                        'student_id' => $recoveryStudent->id,
                        'course_id' => $course->id,
                    ],
                    [
                        'start_date' => now(),
                        'end_date' => now()->addMonth(),
                        'billing_cycle' => 'monthly',
                        'cost' => $course->price,
                        'status' => 'pending',
                    ]
                );
            });

            $recoveryEnrollments->each(function (CoursesEnrollment $enrollment) {
                Payment::updateOrCreate(
                    [
                        'gateway_reference' => 'API-RECOVERY-TEST-' . $enrollment->course_id,
                    ],
                    [
                        'student_id' => $enrollment->student_id,
                        'course_enrollment_id' => $enrollment->id,
                        'amount' => $enrollment->cost,
                        'currency' => 'NGN',
                        'payment_method' => 'card',
                        'gateway' => 'test-gateway',
                        'status' => 'pending',
                        'billing_cycle' => 'monthly',
                        'meta' => [
                            'purpose' => 'registration_recovery_api_test',
                        ],
                        'paid_at' => null,
                    ]
                );
            });

            // $this->command?->line("Admin email: {$admin->email}");
            // $this->command?->line("Admin password: AdminPassword123!");
        });
    }
}
