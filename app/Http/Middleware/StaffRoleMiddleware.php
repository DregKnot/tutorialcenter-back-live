<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StaffRoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  mixed ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $staff = $request->user(); // authenticated staff

        if (!$staff) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 401);
        }

        $userRole = strtolower($staff->role);

        // COO, CSA & Preview roles have full read-only preview access to all admin inspection endpoints
        if (in_array($userRole, ['coo', 'csa', 'customer support', 'customer_support', 'preview', 'operations'])) {
            // Allow all GET / HEAD / OPTIONS read requests
            if ($request->isMethod('get') || $request->isMethod('head') || $request->isMethod('options')) {
                return $next($request);
            }

            // Allow COO to write/edit/delete blogs (COO only)
            if ($userRole === 'coo' && ($request->is('api/staffs/blogs*') || $request->is('api/blogs*') || $request->is('api/staffs/blog-categories*'))) {
                return $next($request);
            }

            // Disallow read-only roles from mutating admin resources (students, staff, exams, courses, etc.)
            return response()->json([
                'message' => 'The ' . strtoupper($userRole) . ' role has read-only access to administrative records and cannot modify data.',
            ], 403);
        }

                $advisorRoles = ['advisor', 'course advisor', 'course_advisor', 'course-advisor'];

        // Allow advisors read-only access to exam data endpoints
        if (in_array($userRole, $advisorRoles) && ($request->is('api/admin/exam-data*') || $request->is('api/advisor/exam-data*'))) {
            if ($request->isMethod('get') || $request->isMethod('head') || $request->isMethod('options')) {
                return $next($request);
            }
        }

        // Normalize roles so any advisor variant satisfies 'advisor' and vice versa
        $normalizedUserRole = in_array($userRole, $advisorRoles) ? 'advisor' : $userRole;
        $allowedRoles = array_map(function ($r) use ($advisorRoles) {
            $lr = strtolower(trim($r));
            return in_array($lr, $advisorRoles) ? 'advisor' : $lr;
        }, $roles);

        if (!in_array($normalizedUserRole, $allowedRoles) && !in_array($userRole, array_map('strtolower', $roles))) {
            return response()->json([
                'message' => 'Access denied. Unauthorized Personal.',
            ], 403);
        }

        return $next($request);
    }
}
