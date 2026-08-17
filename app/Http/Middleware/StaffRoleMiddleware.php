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

        // COO & Preview role has full read-only preview access to all admin inspection endpoints
        if ($userRole === 'coo') {
            // Allow all GET / HEAD / OPTIONS read requests
            if ($request->isMethod('get') || $request->isMethod('head') || $request->isMethod('options')) {
                return $next($request);
            }

            // Allow COO to write/edit/delete blogs
            if ($request->is('api/staffs/blogs*') || $request->is('api/blogs*')) {
                return $next($request);
            }

            // Disallow COO from mutating admin resources (students, staff, exams, courses, etc.)
            return response()->json([
                'message' => 'The COO role has read-only access to administrative records and cannot modify data.',
            ], 403);
        }

        if (!in_array($userRole, array_map('strtolower', $roles))) {
            return response()->json([
                'message' => 'Access denied. Unauthorized Personal.',
            ], 403);
        }

        return $next($request);
    }
}
