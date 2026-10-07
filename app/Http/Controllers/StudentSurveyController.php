<?php

namespace App\Http\Controllers;

use App\Models\StudentSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentSurveyController extends Controller
{
    /**
     * Submit a student survey
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'survey_type' => 'nullable|string|max:50',
            'full_name' => 'nullable|string|max:255',
            'exam_target' => 'nullable|string|max:100',
            'subjects_taken' => 'nullable|array',
            'usage_duration' => 'nullable|string|max:100',
            'overall_rating' => 'nullable|integer|min:1|max:5',
            'live_classes_rating' => 'nullable|integer|min:1|max:5',
            'tutors_rating' => 'nullable|integer|min:1|max:5',
            'study_materials_rating' => 'nullable|integer|min:1|max:5',
            'cbt_rating' => 'nullable|integer|min:1|max:5',
            'platform_rating' => 'nullable|integer|min:1|max:5',
            'navigation_ease' => 'nullable|string|max:100',
            'courses_awareness' => 'nullable|string|max:100',
            'courses_utility_rating' => 'nullable|integer|min:1|max:5',
            'preferred_video_format' => 'nullable|string|max:150',
            'preferred_video_length' => 'nullable|string|max:100',
            'nps_score' => 'nullable|integer|min:0|max:10',
            'wants_followup' => 'nullable|boolean',
            'whatsapp_number' => 'nullable|string|max:30',
            'responses' => 'required|array',
        ]);

        $student = Auth::guard('sanctum')->user();
        $studentId = $student ? $student->id : null;

        // If name wasn't manually filled, fallback to logged-in student name
        $fullName = $validated['full_name'] ?? null;
        if (!$fullName && $student) {
            $fullName = trim(($student->firstname ?? '') . ' ' . ($student->surname ?? ''));
        }

        $survey = StudentSurvey::create([
            'student_id' => $studentId,
            'survey_type' => $validated['survey_type'] ?? 'learning_experience_v1',
            'full_name' => $fullName,
            'exam_target' => $validated['exam_target'] ?? null,
            'subjects_taken' => $validated['subjects_taken'] ?? null,
            'usage_duration' => $validated['usage_duration'] ?? null,
            'overall_rating' => $validated['overall_rating'] ?? null,
            'live_classes_rating' => $validated['live_classes_rating'] ?? null,
            'tutors_rating' => $validated['tutors_rating'] ?? null,
            'study_materials_rating' => $validated['study_materials_rating'] ?? null,
            'cbt_rating' => $validated['cbt_rating'] ?? null,
            'platform_rating' => $validated['platform_rating'] ?? null,
            'navigation_ease' => $validated['navigation_ease'] ?? null,
            'courses_awareness' => $validated['courses_awareness'] ?? null,
            'courses_utility_rating' => $validated['courses_utility_rating'] ?? null,
            'preferred_video_format' => $validated['preferred_video_format'] ?? null,
            'preferred_video_length' => $validated['preferred_video_length'] ?? null,
            'nps_score' => $validated['nps_score'] ?? null,
            'wants_followup' => $validated['wants_followup'] ?? false,
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'responses' => $validated['responses'],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Thank you for taking the time to share your feedback with Tutorial Center Africa! ❤️',
            'data' => [
                'id' => $survey->id,
                'submitted_at' => $survey->created_at,
            ],
        ], 201);
    }

    /**
     * Check if authenticated student has already submitted
     */
    public function status(Request $request): JsonResponse
    {
        $student = Auth::guard('sanctum')->user();
        if (!$student) {
            return response()->json([
                'has_submitted' => false,
            ]);
        }

        $survey = StudentSurvey::where('student_id', $student->id)
            ->where('survey_type', 'learning_experience_v1')
            ->latest()
            ->first();

        return response()->json([
            'has_submitted' => (bool) $survey,
            'submitted_at' => $survey ? $survey->created_at : null,
        ]);
    }

    /**
     * Admin analytics summary
     */
    public function adminSummary(Request $request): JsonResponse
    {
        $total = StudentSurvey::count();

        if ($total === 0) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'total_responses' => 0,
                    'ratings' => [
                        'overall' => 0,
                        'live_classes' => 0,
                        'tutors' => 0,
                        'study_materials' => 0,
                        'cbt' => 0,
                        'platform' => 0,
                        'courses_utility' => 0,
                    ],
                    'nps' => [
                        'score' => 0,
                        'promoters_pct' => 0,
                        'passives_pct' => 0,
                        'detractors_pct' => 0,
                    ],
                    'video_formats' => [],
                    'video_lengths' => [],
                    'exam_targets' => [],
                    'recent_responses' => [],
                ],
            ]);
        }

        // Average ratings
        $avgRatings = [
            'overall' => round((float) StudentSurvey::avg('overall_rating'), 2),
            'live_classes' => round((float) StudentSurvey::avg('live_classes_rating'), 2),
            'tutors' => round((float) StudentSurvey::avg('tutors_rating'), 2),
            'study_materials' => round((float) StudentSurvey::avg('study_materials_rating'), 2),
            'cbt' => round((float) StudentSurvey::avg('cbt_rating'), 2),
            'platform' => round((float) StudentSurvey::avg('platform_rating'), 2),
            'courses_utility' => round((float) StudentSurvey::avg('courses_utility_rating'), 2),
        ];

        // NPS Calculation (0-6 detractors, 7-8 passives, 9-10 promoters)
        $promotersCount = StudentSurvey::where('nps_score', '>=', 9)->count();
        $passivesCount = StudentSurvey::whereBetween('nps_score', [7, 8])->count();
        $detractorsCount = StudentSurvey::where('nps_score', '<=', 6)->whereNotNull('nps_score')->count();
        $npsRespondents = $promotersCount + $passivesCount + $detractorsCount;

        $npsScore = 0;
        $promotersPct = 0;
        $passivesPct = 0;
        $detractorsPct = 0;

        if ($npsRespondents > 0) {
            $promotersPct = round(($promotersCount / $npsRespondents) * 100, 1);
            $passivesPct = round(($passivesCount / $npsRespondents) * 100, 1);
            $detractorsPct = round(($detractorsCount / $npsRespondents) * 100, 1);
            $npsScore = round($promotersPct - $detractorsPct, 1);
        }

        // Video Format Distribution
        $formats = StudentSurvey::select('preferred_video_format', DB::raw('count(*) as count'))
            ->whereNotNull('preferred_video_format')
            ->groupBy('preferred_video_format')
            ->orderByDesc('count')
            ->get()
            ->map(function ($row) use ($total) {
                return [
                    'format' => $row->preferred_video_format,
                    'count' => (int) $row->count,
                    'percentage' => round(((int) $row->count / $total) * 100, 1),
                ];
            });

        // Video Length Distribution
        $lengths = StudentSurvey::select('preferred_video_length', DB::raw('count(*) as count'))
            ->whereNotNull('preferred_video_length')
            ->groupBy('preferred_video_length')
            ->orderByDesc('count')
            ->get()
            ->map(function ($row) use ($total) {
                return [
                    'length' => $row->preferred_video_length,
                    'count' => (int) $row->count,
                    'percentage' => round(((int) $row->count / $total) * 100, 1),
                ];
            });

        // Exam Target Distribution
        $exams = StudentSurvey::select('exam_target', DB::raw('count(*) as count'))
            ->whereNotNull('exam_target')
            ->groupBy('exam_target')
            ->orderByDesc('count')
            ->get()
            ->map(function ($row) use ($total) {
                return [
                    'exam' => $row->exam_target,
                    'count' => (int) $row->count,
                    'percentage' => round(((int) $row->count / $total) * 100, 1),
                ];
            });

        // Recent 50 responses
        $recent = StudentSurvey::with('student:id,firstname,surname,email,phone')
            ->recent()
            ->limit(50)
            ->get()
            ->map(function ($s) {
                $resp = $s->responses ?? [];
                return [
                    'id' => $s->id,
                    'student_id' => $s->student_id,
                    'full_name' => $s->full_name ?: ($s->student ? "{$s->student->firstname} {$s->student->surname}" : 'Anonymous Student'),
                    'email' => $s->student ? $s->student->email : null,
                    'phone' => $s->student ? $s->student->phone : null,
                    'exam_target' => $s->exam_target,
                    'overall_rating' => $s->overall_rating,
                    'preferred_video_format' => $s->preferred_video_format,
                    'preferred_video_length' => $s->preferred_video_length,
                    'nps_score' => $s->nps_score,
                    'wants_followup' => $s->wants_followup,
                    'whatsapp_number' => $s->whatsapp_number,
                    'likes_most' => $resp['q12_likes_most'] ?? ($resp['likes_most'] ?? null),
                    'one_improvement' => $resp['q14_one_thing_improve'] ?? ($resp['one_thing_improve'] ?? null),
                    'format_reason' => $resp['q23_format_reason'] ?? ($resp['format_reason'] ?? null),
                    'nps_reason' => $resp['q41_nps_reason'] ?? ($resp['nps_reason'] ?? null),
                    'created_at' => $s->created_at->format('M d, Y h:i A'),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_responses' => $total,
                'ratings' => $avgRatings,
                'nps' => [
                    'score' => $npsScore,
                    'promoters_pct' => $promotersPct,
                    'passives_pct' => $passivesPct,
                    'detractors_pct' => $detractorsPct,
                    'promoters_count' => $promotersCount,
                    'passives_count' => $passivesCount,
                    'detractors_count' => $detractorsCount,
                ],
                'video_formats' => $formats,
                'video_lengths' => $lengths,
                'exam_targets' => $exams,
                'recent_responses' => $recent,
            ],
        ]);
    }

    /**
     * Show single survey details
     */
    public function adminShow($id): JsonResponse
    {
        $survey = StudentSurvey::with('student')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $survey,
        ]);
    }

    /**
     * Export all responses to CSV
     */
    public function adminExport(): StreamedResponse
    {
        $fileName = 'tutorialcenter_student_surveys_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Microsoft Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // CSV Header
            fputcsv($handle, [
                'Survey ID',
                'Student Name',
                'Email',
                'Phone',
                'Exam Target',
                'Usage Duration',
                'Overall Rating (1-5)',
                'Live Classes Rating (1-5)',
                'Tutors Rating (1-5)',
                'Study Materials Rating (1-5)',
                'CBT Rating (1-5)',
                'Platform Rating (1-5)',
                'Platform Navigation Ease',
                'Courses Awareness',
                'Courses Utility Rating (1-5)',
                'Preferred Video Format',
                'Format Reason',
                'Preferred Video Length',
                'Short Quizzes After Topic',
                'Past Questions in Video',
                'Captions Preferred',
                'Adjustable Playback Speed',
                'Best Understanding Aid',
                'What Should Be In Course',
                'Next Step After Video',
                'Study Time Preference',
                'Device Used',
                'NPS Score (0-10)',
                'NPS Rating Reason',
                'Likes Most',
                'Areas to Improve',
                'One Major Improvement',
                'Experienced Problems',
                'Problem Details',
                'New Feature Request',
                'Wants Follow-up',
                'WhatsApp Number',
                'Submitted At',
            ]);

            StudentSurvey::with('student')->chunk(100, function ($surveys) use ($handle) {
                foreach ($surveys as $s) {
                    $r = $s->responses ?? [];
                    fputcsv($handle, [
                        $s->id,
                        $s->full_name ?: ($s->student ? "{$s->student->firstname} {$s->student->surname}" : 'Anonymous'),
                        $s->student ? $s->student->email : '',
                        $s->student ? $s->student->phone : '',
                        $s->exam_target ?? '',
                        $s->usage_duration ?? '',
                        $s->overall_rating ?? '',
                        $s->live_classes_rating ?? '',
                        $s->tutors_rating ?? '',
                        $s->study_materials_rating ?? '',
                        $s->cbt_rating ?? '',
                        $s->platform_rating ?? '',
                        $s->navigation_ease ?? '',
                        $s->courses_awareness ?? '',
                        $s->courses_utility_rating ?? '',
                        $s->preferred_video_format ?? '',
                        $r['q23_format_reason'] ?? ($r['format_reason'] ?? ''),
                        $s->preferred_video_length ?? '',
                        $r['q27_short_quizzes'] ?? '',
                        $r['q28_past_questions'] ?? '',
                        $r['q29_captions'] ?? '',
                        $r['q30_playback_speed'] ?? '',
                        is_array($r['q26_best_understanding'] ?? null) ? implode('; ', $r['q26_best_understanding']) : ($r['q26_best_understanding'] ?? ''),
                        is_array($r['q31_course_contents'] ?? null) ? implode('; ', $r['q31_course_contents']) : ($r['q31_course_contents'] ?? ''),
                        $r['q32_next_step'] ?? '',
                        $r['q33_study_time'] ?? '',
                        $r['q34_device'] ?? '',
                        $s->nps_score ?? '',
                        $r['q41_nps_reason'] ?? ($r['nps_reason'] ?? ''),
                        $r['q12_likes_most'] ?? ($r['likes_most'] ?? ''),
                        is_array($r['q13_improve_areas'] ?? null) ? implode('; ', $r['q13_improve_areas']) : ($r['q13_improve_areas'] ?? ''),
                        $r['q14_one_thing_improve'] ?? ($r['one_thing_improve'] ?? ''),
                        $r['q15_had_problem'] ?? '',
                        $r['q16_problem_details'] ?? '',
                        $r['q36_new_feature'] ?? ($r['new_feature'] ?? ''),
                        $s->wants_followup ? 'Yes' : 'No',
                        $s->whatsapp_number ?? '',
                        $s->created_at ? $s->created_at->toDateTimeString() : '',
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }
}
