<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\LoginRequest;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/admin/auth/login
     *
     * No public registration for employees, by design - accounts are
     * created through staff-management functionality (a later step). Any
     * employee role logs in here; this URL prefix doesn't gate by role -
     * role isn't known until after the token resolves, so there's nothing
     * to gate at login time. See STEP 04 plan §3.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $employee = Employee::where('email', $request->validated('email'))->first();

        if (! $employee || ! Hash::check($request->validated('password'), $employee->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        if ($employee->status !== 'active') {
            throw new AuthorizationException('Your account is inactive. Please contact your administrator.');
        }

        $token = $employee->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'employee' => $employee,
            'token' => $token,
        ], 'Login successful.');
    }

    /**
     * POST /api/v1/staff/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    /**
     * GET /api/v1/staff/me
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user(), 'OK');
    }
}
