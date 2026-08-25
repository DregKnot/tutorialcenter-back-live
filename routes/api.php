<?php

use App\Http\Controllers\AdminDashboardAnalyticsController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AdvisorDashboardController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ClassesController;
use App\Http\Controllers\CognitiveTestController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\ExamActivityController;
use App\Http\Controllers\ExamBodyController;
use App\Http\Controllers\ExamInteractionController;
use App\Http\Controllers\ExamYearController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\GuardianDashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PastQuestionController;
use App\Http\Controllers\PastQuestionGroupController;
use App\Http\Controllers\PastQuestionOptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SpecialEventCalendarController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudentAchievementController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentExamController;
use App\Http\Controllers\StudentExamQuestionController;
use App\Http\Controllers\StudentExamResultController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ZoomController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/courses', [CourseController::class, 'index']); // Public: List all active courses
Route::get('/subjects', [SubjectController::class, 'index']); // Public: List all active subjects

// Cognitive tests
Route::get('/cognitive-tests', [CognitiveTestController::class, 'index']);
Route::post('/cognitive-tests/start', [CognitiveTestController::class, 'store']);
Route::post('/cognitive-tests/{cognitiveTest}/complete', [CognitiveTestController::class, 'complete']);
Route::post('/course/enrollment', [CourseController::class, 'courseEnroll']); // Public: Enroll in a course
Route::post('/subject/enrollment', [SubjectController::class, 'subjectEnroll']); // Public: Enroll in a subject
Route::get('/courses/{courseId}/subjects', [SubjectController::class, 'subjectsByCourse']); // Public: List subjects by course
Route::get('/courses/{courseId}/subjects/{department}', [SubjectController::class, 'subjectsByCourseAndDepartment']); // Public: List subjects by course and department
Route::post('payments', [PaymentController::class, 'store']);

// Blog Public Routes
Route::get('/blogs', [BlogController::class, 'index']);
Route::get('/blogs/categories', [BlogController::class, 'categories']);
Route::get('/blogs/{slug}', [BlogController::class, 'show']);
Route::post('/blogs/{id}/comments', [BlogController::class, 'storeComment']);
 // Public: Process payment

/*
|--------------------------------------------------------------------------
| Authenticated User Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
});

/*
|--------------------------------------------------------------------------
| Student Public Routes
|--------------------------------------------------------------------------
*/
Route::prefix('students')->group(function () {
    Route::post('/send-phone-otp', [StudentController::class, 'sendPhoneOtp']); // Send Phone OTP
    Route::post('/login', [StudentController::class, 'login']); // Login Method
    // Route::post('/register', [StudentController::class, 'store']); // Registration
    // Route::post('/biodata', [StudentController::class, 'biodata']); // Biodata completion (NO AUTH REQUIRED, but verification enforced)
    Route::post('/verify-email', [StudentController::class, 'verifyEmail']); // Email verification
    Route::post('/enroll-course', [CourseController::class, 'courseEnroll']); // Course enrollment
    Route::post('/verify-phone', [StudentController::class, 'verifyPhoneOtp']); // Phone OTP verification
    Route::post('/register', [StudentController::class, 'registerWithBiodata']); // Registration
    Route::post('/forget-password', [StudentController::class, 'forgetPassword']); // Forget Password
    Route::post('/change-password', [StudentController::class, 'changePassword']); // Change Password with OTP
    Route::post('/resend-phone-otp', [StudentController::class, 'resendPhoneOtp']); // Resend Phone Verification
    Route::post('/resend-email-verification', [StudentController::class, 'resendEmailVerification']); // Resend Email Verification
});

/*
|--------------------------------------------------------------------------
| Student Protected Routes
|--------------------------------------------------------------------------
*/
Route::prefix('students')->middleware('auth:sanctum')->group(function () {
    // Achievement endpoints: list the student's awards and current progress.
    Route::get('/achievements', [StudentAchievementController::class, 'index']); // List active achievements and earned awards
    Route::get('/achievements/progress', [StudentAchievementController::class, 'progress']); // Get milestone, streak, time, and weekly progress
    Route::get('/leaderboard', [AdminDashboardAnalyticsController::class, 'leaderboard']); // Leaderboard
        Route::get('/leaderboard/students/{id}', [AdminDashboardAnalyticsController::class, 'studentLeaderboardDetail']); // Student Subject & Daily Leaderboard Detail
    Route::post('/zoom/signature', [ZoomController::class, 'generateSignature']);
    Route::post('/logout', [StudentController::class, 'logout']); // Logout Method
    Route::get('/streak', [StudentController::class, 'learningStreak']); // Get ongoing and maximum learning streak
    Route::put('/profile/update', [StudentController::class, 'update']); // Update student profile
    Route::get('/payments', [PaymentController::class, 'myPayments']); // Listing out all payments
    Route::post('/attendance', [AttendanceController::class, 'store']); // Record attendance for a class session
    Route::get('/courses', [CourseController::class, 'getActiveCourses']); // Get Active Courses and Subject
    Route::get('/class/schedule', [ClassesController::class, 'studentClassSchedule']); // Get student schedule with attendance status
    Route::get('/calendar/schedule', [ClassesController::class, 'studentCalenderSchedule']); // Get student schedule (classes and sessions)
    Route::post('/courses/disenroll/{courseId}', [CourseController::class, 'disenrollCourse']); // Course disenrollment
    Route::post('/contact/change/request', [StudentController::class, 'requestContactChange']); // Request contact change (phone or email)
    Route::post('/contact/change/confirm', [StudentController::class, 'confirmContactChange']); // Verify contact change with OTP
    // Route::post('/phone/change/resend-otp', [StudentController::class, 'resendPhoneChangeOtp']); // Resend OTP for phone number change

    // Notification Routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    // Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);

    /*
    |--------------------------------------------------------------------------
    | Student Exam Routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('exams')->group(function () {
        Route::get('/available', [StudentExamController::class, 'available']); // List exams student can access
        Route::post('/start/{examYear}', [StudentExamController::class, 'start']); // Start an exam
        Route::get('/{attempt}/questions', [StudentExamQuestionController::class, 'questions']); // Get questions for an attempt
        Route::post('/{attempt}/questions/{question}/view', [ExamInteractionController::class, 'viewed']);
        Route::post('/{attempt}/questions/{question}/answer', [ExamInteractionController::class, 'answered']);
        Route::post('/{attempt}/questions/{question}/skip', [ExamInteractionController::class, 'skipped']);
        Route::post('/{attempt}/activity/start', [ExamActivityController::class, 'start']);
        Route::post('/{attempt}/activity/heartbeat', [ExamActivityController::class, 'heartbeat']);
        Route::post('/{attempt}/activity/end', [ExamActivityController::class, 'end']);
        Route::post('/{attempt}/answer', [StudentExamQuestionController::class, 'submitAnswer']); // Save/update answer
        Route::post('/{attempt}/submit', [StudentExamResultController::class, 'submit']); // Submit and finish exam
        Route::get('/results/history', [StudentExamResultController::class, 'history']); // Student attempt history
        Route::get('/{attempt}/review', [StudentExamResultController::class, 'review']); // Review attempt with answers and explanations
    });

    // Recorded Classes
    Route::get('/recorded-classes', [ClassesController::class, 'getRecordedClasses']);

    // Feedback Routes
    Route::prefix('feedback')->group(function () {
        Route::get('/', [FeedbackController::class, 'index']); // My feedback history
        Route::post('/', [FeedbackController::class, 'store']); // Submit feedback
        Route::get('/{feedback}', [FeedbackController::class, 'show']); // View one feedback
        // Route::put('/{feedback}',[FeedbackController::class, 'update']); // Update my feedback
        // Route::patch('/{feedback}',[FeedbackController::class, 'update']); // Update my feedback (PATCH alternative)
    });



        // Support Routes
    Route::prefix('support')->group(function () {
        Route::get('/', [SupportController::class, 'index']); // List my support tickets
        Route::post('/', [SupportController::class, 'store']); // Create a new support ticket
        Route::get('/{supportTicket}', [SupportController::class, 'show']); // View a specific support ticket
        Route::post('/{supportTicket}/reply', [SupportController::class, 'reply']); // Reply to a specific support ticket
        Route::patch('/{supportTicket}/close', [SupportController::class, 'close']); // Close a specific support ticket
        Route::patch('/{supportTicket}/reopen', [SupportController::class, 'reopen']); // Reopen a specific support ticket
        // Route::delete('/{supportTicket}',[SupportController::class, 'destroy']); // Delete a specific support ticket (if needed)
    });
});

/*
|--------------------------------------------------------------------------
| Student Public Route
|--------------------------------------------------------------------------
*/
Route::prefix('students')->group(function () {
    Route::post('/phone/change/confirm', [StudentController::class, 'confirmPhoneNumberChange']); // Confirm phone number change with OTP verification
});

/*
|--------------------------------------------------------------------------
| Guardian Registration & Verification
|--------------------------------------------------------------------------
*/
Route::get('/guardians/all', [StudentController::class, 'allGuardians']);
    Route::get('/advisors/all', [StudentController::class, 'allAdvisors']);

    Route::prefix('guardians')->group(function () {
    // Route::post('/register', [GuardianController::class, 'store']);
    Route::post('/register', [GuardianController::class, 'registerWithBiodata']);

    Route::post('/verify-email', [GuardianController::class, 'verifyEmail']);

    Route::post('/verify-phone', [GuardianController::class, 'verifyPhoneOtp']);
    Route::post('/resend-phone-otp', [GuardianController::class, 'resendPhoneOtp']);

    Route::post('/resend-email', [GuardianController::class, 'resendEmailVerification']);
    Route::post('/login', [GuardianController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| Guardian Protected Routes
|--------------------------------------------------------------------------
*/
Route::prefix('guardians')->middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [GuardianController::class, 'profile']);
    Route::post('/profile/update', [GuardianController::class, 'update']);
    Route::get('/dashboard/wards', [GuardianDashboardController::class, 'getWardsDashboard']);
    Route::get('/dashboard/wards/{student_id}/performance', [GuardianDashboardController::class, 'getWardPerformance']);
    Route::get('/dashboard/wards/{student_id}/attendance', [GuardianDashboardController::class, 'getWardAttendance']);
    Route::post('/logout', [GuardianController::class, 'logout']);
});

/*
|--------------------------------------------------------------------------
| Staff Registration Verification
|--------------------------------------------------------------------------
*/
Route::get('/audit-logs', [\App\Http\Controllers\NotificationController::class, 'adminAuditLogs']);

    Route::prefix('staffs')->group(function () {
    Route::get('/leaderboard', [AdminDashboardAnalyticsController::class, 'leaderboard']); // Leaderboard
        Route::get('/leaderboard/students/{id}', [AdminDashboardAnalyticsController::class, 'studentLeaderboardDetail']); // Student Subject & Daily Leaderboard Detail
    Route::post('/zoom/signature', [ZoomController::class, 'generateSignature']);
    // Login (restricted until verified)
    Route::post('/login', [StaffController::class, 'login']);

    // Password reset
    Route::post('/forgot-password', [StaffController::class, 'forgotPassword']);
    Route::post('/reset-password', [StaffController::class, 'resetPassword']);

    // Email verification
    Route::post('/verify-email', [StaffController::class, 'verifyEmail']);
    Route::post('/resend-email-verification', [StaffController::class, 'resendEmailVerification']);

    // Phone OTP verification
    Route::post('/verify-phone', [StaffController::class, 'verifyPhoneOtp']);
    Route::post('/resend-phone-otp', [StaffController::class, 'resendPhoneOtp']);

    Route::middleware('auth:staff')->group(function () {
        // Logout
        Route::post('/logout', [StaffController::class, 'logout']);

        // Classes
        Route::post('/classes/session/recording', [ClassesController::class, 'updateSessionRecording']); // Update recording link for a session

        // Notification Routes
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);

        // Feedback Routes
        Route::prefix('feedback')->group(function () {
            Route::get('/', [FeedbackController::class, 'index']); // My feedback history
            Route::post('/', [FeedbackController::class, 'store']); // Submit feedback
            Route::get('/{feedback}', [FeedbackController::class, 'show']); // View one feedback
            // Route::put('/{feedback}', [FeedbackController::class, 'update']); // Update my feedback
            // Route::patch('/{feedback}', [FeedbackController::class, 'update']); // Update my feedback (PATCH alternative)
            // Route::delete('/{feedback}', [FeedbackController::class, 'destroy']); // Delete my feedback
        });

        // Support Routes
        Route::prefix('support')->group(function () {
            Route::get('/', [SupportController::class, 'index']); // List my support tickets
            Route::post('/', [SupportController::class, 'store']); // Create a new support ticket
            Route::get('/{supportTicket}', [SupportController::class, 'show']); // View a specific support ticket
            Route::post('/{supportTicket}/reply', [SupportController::class, 'reply']); // Reply to a specific support ticket
            Route::patch('/{supportTicket}/close', [SupportController::class, 'close']); // Close a specific support ticket
            Route::patch('/{supportTicket}/reopen', [SupportController::class, 'reopen']); // Reopen a specific support ticket
            // Route::delete('/{supportTicket}',[SupportController::class, 'destroy']); // Delete a specific support ticket (if needed)
        });

        // Blog Management (COO & Admin)
        Route::prefix('blogs')->group(function () {
            Route::get('/', [BlogController::class, 'index']);
            Route::post('/', [BlogController::class, 'store']);
            Route::get('/{id}', [BlogController::class, 'show']);
            Route::post('/{id}', [BlogController::class, 'update']);
            Route::delete('/{id}', [BlogController::class, 'destroy']);
        });
        Route::get('/blog-categories', [BlogController::class, 'categories']);
    });
});

/*
 * Admin Only Protected Routes (enforced in controller)
 */
Route::prefix('admin')->middleware(['auth:sanctum', 'auth:staff', 'staff.role:admin,moderator,coo'])->group(function () {
    Route::get('/feedbacks/all', [\App\Http\Controllers\FeedbackController::class, 'adminIndex']);
    Route::patch('/feedbacks/{id}/status', [\App\Http\Controllers\FeedbackController::class, 'adminToggleStatus']);
    Route::delete('/feedbacks/{id}/admin', [\App\Http\Controllers\FeedbackController::class, 'adminDestroy']);


    // Guardians Management
    Route::get('/guardians/all', [StudentController::class, 'allGuardians']);
    Route::get('/advisors/all', [StudentController::class, 'allAdvisors']);

    Route::prefix('guardians')->group(function () {
        Route::get('/all', [App\Http\Controllers\AdvisorDashboardController::class, 'guardians']);
    });

    Route::prefix('dashboard')->group(function () {
        Route::get('/leaderboard', [AdminDashboardAnalyticsController::class, 'leaderboard']); // Leaderboard
        Route::get('/leaderboard/students/{id}', [AdminDashboardAnalyticsController::class, 'studentLeaderboardDetail']); // Student Subject & Daily Leaderboard Detail
        Route::get('/mock-analytics', [AdminDashboardAnalyticsController::class, 'examAnalytics']); // Exam Analytics
    });

    // Staffs Management
    Route::get('/audit-logs', [\App\Http\Controllers\NotificationController::class, 'adminAuditLogs']);

    Route::prefix('staffs')->group(function () {
        Route::get('/all', [StaffController::class, 'index']); // List all staff members
        Route::get('/{id}', [StaffController::class, 'show']); // View a specific staff member's details
        Route::post('/register', [StaffController::class, 'store']); // Registration a new Staff
        Route::put('/update/{id}', [StaffController::class, 'update']); // Update staff member details
        Route::post('/restore/{id}', [StaffController::class, 'restore']); // Restore a soft-deleted staff member
        Route::delete('/destroy/{id}', [StaffController::class, 'destroy']); // Soft delete a staff member
        Route::post('/active', [StaffController::class, 'activeStaffs']); // Test Route
    });

    // Course Management
    Route::prefix('courses')->group(function () {
        Route::post('/', [CourseController::class, 'store']);
        Route::put('/update/{id}', [CourseController::class, 'update']);
        Route::delete('/destroy/{id}', [CourseController::class, 'destroy']);
        Route::post('/restore/{id}', [CourseController::class, 'restore']);
        Route::get('/disenrollments', [CourseController::class, 'getDisenrolledCourses']); // List all course disenrollments
    });

    // Subject Management
    Route::prefix('subjects')->group(function () {
        Route::get('/all', [SubjectController::class, 'allSubjects']); // View all subjects (including inactive)
        Route::post('/', [SubjectController::class, 'store']); // Create new subject
        Route::put('/update/{id}', [SubjectController::class, 'update']); // Update subject
        Route::delete('/destroy/{id}', [SubjectController::class, 'destroy']); // Soft delete subject
        Route::post('/restore/{id}', [SubjectController::class, 'restore']); // Restore soft-deleted subject
    });

    // Classes Management
    Route::prefix('classes')->group(function () {
        Route::post('/create', [ClassesController::class, 'store']); // Create class, class schedule, assign staff to class and class sessions
        Route::get('/all', [ClassesController::class, 'allClassesSchedule']); // List all classes
    });

    // Student Management
    Route::prefix('students')->group(function () {
        Route::post('/complimentary-registration', [StudentController::class, 'createComplimentaryRegistration'])
            ->middleware('staff.role:admin');
        Route::post('/restore/{id}', [StudentController::class, 'restore']); // Restore a soft-deleted student
        Route::delete('/destroy/{id}', [StudentController::class, 'destroy']); // Soft delete a student
        Route::get('/all', [StudentController::class, 'index']); // List all students
        Route::get('/{id}', [StudentController::class, 'show']); // Show student details
        Route::put('/update', [StudentController::class, 'update']); // Update student profile
        Route::post('/{id}/guardians', [StudentController::class, 'assignGuardian']);
        Route::delete('/{id}/guardians/{guardianId}', [StudentController::class, 'detachGuardian']);
        Route::post('/{id}/advisors', [StudentController::class, 'assignAdvisor']);
        Route::delete('/{id}/advisors/{staffId}', [StudentController::class, 'detachAdvisor']);
    });

    // Exam Body Management
    Route::prefix('exam-bodies')->group(function () {
        Route::get('/all', [ExamBodyController::class, 'index']); // List all exam bodies (including inactive)
        Route::post('/', [ExamBodyController::class, 'store']); // Create new exam body
        Route::get('/{id}', [ExamBodyController::class, 'show']); // Show exam body details
        Route::put('/update/{id}', [ExamBodyController::class, 'update']); // Update exam body
        Route::delete('/destroy/{id}', [ExamBodyController::class, 'destroy']); // Soft delete exam body
    });

    // Exam Data Management
    Route::prefix('exam-data')->group(function () {

        // List exam bodies
        Route::get('/bodies', [ExamYearController::class, 'examBodies']);

        // List subjects with exam year count
        Route::get('/subjects', [ExamYearController::class, 'subjects']);

        // List exam years
        Route::get('/years', [ExamYearController::class, 'years']);

        // List questions
        Route::get('/questions', [ExamYearController::class, 'questions']);
    });

    // Exam Year Management
    Route::prefix('exam-years')->group(function () {
        Route::get('/all', [ExamYearController::class, 'index']); // List all exam years (including inactive)
        Route::post('/', [ExamYearController::class, 'store']); // Create new exam year
        Route::get('/{id}', [ExamYearController::class, 'show']); // Show exam year details
        Route::put('/update/{id}', [ExamYearController::class, 'update']); // Update exam year
        Route::delete('/destroy/{id}', [ExamYearController::class, 'destroy']); // Soft delete exam year
    });

    // Past Question Group Management
    Route::prefix('past-question-groups')->group(function () {
        Route::post('/', [PastQuestionGroupController::class, 'store']); // Create new past question group
        Route::get('/all', [PastQuestionGroupController::class, 'index']); // List all past question groups (including inactive)
        Route::get('/{id}', [PastQuestionGroupController::class, 'show']); // Show past question group details
        Route::put('/update/{id}', [PastQuestionGroupController::class, 'update']); // Update past question group
        Route::post('/restore/{id}', [PastQuestionGroupController::class, 'restore']); // Restore soft-deleted past question group
        Route::delete('/destroy/{id}', [PastQuestionGroupController::class, 'destroy']); // Soft delete past question group
    });

    // Past Question Management
    Route::prefix('past-questions')->group(function () {
        Route::post('/', [PastQuestionController::class, 'store']); // Create new past question
        Route::get('/all', [PastQuestionController::class, 'index']); // List all past questions (including inactive)
        Route::get('/{id}', [PastQuestionController::class, 'show']); // Show past question details
        Route::put('/update/{id}', [PastQuestionController::class, 'update']); // Update past question
        Route::post('/restore/{id}', [PastQuestionController::class, 'restore']); // Restore soft-deleted past question
        Route::delete('/destroy/{id}', [PastQuestionController::class, 'destroy']); // Soft delete past question
    });

    // Past Question Option Management
    Route::prefix('past-question-options')->group(function () {
        Route::put('/update/{id}', [PastQuestionOptionController::class, 'update']); // Update past question option
        Route::post('/restore/{id}', [PastQuestionOptionController::class, 'restore']); // Restore soft-deleted past question option
        Route::delete('/destroy/{id}', [PastQuestionOptionController::class, 'destroy']); // Soft delete past question option
    });

    // Payment Management
    Route::prefix('payments')->group(function () {
        Route::get('/all', [PaymentController::class, 'index']); // List all payments with filters
        Route::get('/registration-recovery/search', [PaymentController::class, 'searchRegistrationRecovery']);
        Route::post('/{payment}/registration-recovery', [PaymentController::class, 'completeRegistrationRecovery'])
            ->middleware('staff.role:admin');
    });

    Route::prefix('special-event-calendars')->group(function () {
        // Special-event calendar management for country-specific achievement windows.
        Route::get('/', [SpecialEventCalendarController::class, 'index']); // List calendars and filter by country, event, status, or year
        Route::get('/{specialEventCalendar}', [SpecialEventCalendarController::class, 'show']); // View one calendar entry
        Route::post('/', [SpecialEventCalendarController::class, 'store'])
            ->middleware('staff.role:admin'); // Create a calendar entry; local dates are stored as UTC
        Route::put('/{specialEventCalendar}', [SpecialEventCalendarController::class, 'update'])
            ->middleware('staff.role:admin'); // Replace a calendar entry
        Route::patch('/{specialEventCalendar}', [SpecialEventCalendarController::class, 'update'])
            ->middleware('staff.role:admin'); // Partially update a calendar entry
        Route::delete('/{specialEventCalendar}', [SpecialEventCalendarController::class, 'destroy'])
            ->middleware('staff.role:admin'); // Deactivate a calendar entry without deleting history
    });

    // Notification Routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);

    // Feedback Routes
    Route::prefix('feedback')->group(function () {
        Route::get('/', [FeedbackController::class, 'index']); // My feedback history
        Route::post('/', [FeedbackController::class, 'store']); // Submit feedback
        Route::get('/{feedback}', [FeedbackController::class, 'show']); // View one feedback
        Route::put('/{feedback}', [FeedbackController::class, 'update']); // Update my feedback
        Route::patch('/{feedback}', [FeedbackController::class, 'update']); // Update my feedback (PATCH alternative)
        Route::delete('/{feedback}', [FeedbackController::class, 'destroy']); // Delete my feedback
    });

    // Support Routes
    Route::prefix('support')->group(function () {
        Route::get('/', [AdminSupportController::class, 'index']); // List all support tickets
        Route::get('/analytics', [AdminSupportController::class, 'analytics']); // Support analytics
        Route::get('/{supportTicket}', [AdminSupportController::class, 'show']); // View a specific support ticket
        Route::patch('/{supportTicket}/assign', [AdminSupportController::class, 'assign']); // Assign a specific support ticket to a staff member
        Route::post('/{supportTicket}/reply', [AdminSupportController::class, 'reply']); // Reply to a specific support ticket
        Route::patch('/{supportTicket}/status', [AdminSupportController::class, 'status']); // Update the status of a specific support ticket
        Route::patch('/{supportTicket}/priority', [AdminSupportController::class, 'priority']); // Update the priority of a specific support ticket
        Route::delete('/{supportTicket}', [AdminSupportController::class, 'destroy']); // Delete a specific support ticket
    });
});

/*
 * Tutor Only Protected Routes (enforced in controller)
 */
Route::prefix('tutor')->middleware(['auth:sanctum', 'auth:staff', 'staff.role:tutor'])->group(function () {
    Route::prefix('classes')->group(function () {
        Route::get('/schedule', [ClassesController::class, 'tutorClassesSchedule']); // Get tutor schedule with attendance status
    });
});

/*
 * Tutor Only Protected Routes (enforced in controller)
 */
Route::prefix('advisor')->middleware(['auth:sanctum', 'auth:staff', 'staff.role:advisor'])->group(function () {
    Route::prefix('classes')->group(function () {
        Route::get('/schedule', [ClassesController::class, 'advisorClassesSchedule']); // Get advisor schedule with attendance status
    });

    // Advisor Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [AdvisorDashboardController::class, 'stats']); // Get average points and attempts
    });

    // Guardians Management
    Route::get('/guardians/all', [StudentController::class, 'allGuardians']);
    Route::get('/advisors/all', [StudentController::class, 'allAdvisors']);

    Route::prefix('guardians')->group(function () {
        Route::get('/all', [AdvisorDashboardController::class, 'guardians']); // List all guardians and their wards
    });

    // Student Management
    Route::prefix('students')->group(function () {
        // Route::post('/restore/{id}', [StudentController::class, 'restore']); // Restore a soft-deleted student
        // Route::delete('/destroy/{id}', [StudentController::class, 'destroy']); // Soft delete a student
        Route::get('/all', [StudentController::class, 'index']); // List all students
        Route::get('/{id}', [StudentController::class, 'show']); // Show student details
    });
});

Route::get('/staffs/audit-logs', [\App\Http\Controllers\NotificationController::class, 'adminAuditLogs']);

Route::get('/staffs/feedbacks/all', [\App\Http\Controllers\FeedbackController::class, 'adminIndex']);
