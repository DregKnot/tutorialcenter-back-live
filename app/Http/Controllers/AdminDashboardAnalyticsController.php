<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Admin\ExamAnalyticsService;

class AdminDashboardAnalyticsController extends Controller
{
    protected ExamAnalyticsService $analytics;

    public function __construct(
        ExamAnalyticsService $analytics
    ) {
        $this->analytics = $analytics;
    }

    /**
     * Exam Practice Analytics
     */
    public function examAnalytics(Request $request)
    {
        try {

            $analytics = $this->analytics->overview();

            return response()->json([
                'success' => true,
                'message' => 'Exam analytics retrieved successfully.',
                'data' => $analytics,
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve exam analytics.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Leaderboard of students based on average score and total attempts
     */
    public function leaderboard(
        ExamAnalyticsService $analytics
    ) {
        return response()->json([
            'success' => true,
            'data' => $analytics->leaderboard(),
        ]);
    }

    /**
     * Student Leaderboard Subject & Daily Breakdown
     */
    public function studentLeaderboardDetail($studentId, ExamAnalyticsService $analytics)
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $analytics->studentLeaderboardDetail((int) $studentId),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve student leaderboard detail.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
