<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\LoginRequest;
use App\Http\Requests\Customer\RegisterRequest;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/customer/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $customer = Customer::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            // Hashed automatically by the model's 'password' => 'hashed'
            // cast - never hashed manually here.
            'password' => $request->validated('password'),
            'phone' => $request->validated('phone'),
            'address' => $request->validated('address'),
        ]);

        $token = $customer->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'customer' => $customer,
            'token' => $token,
        ], 'Registration successful.', 201);
    }

    /**
     * POST /api/v1/customer/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $customer = Customer::where('email', $request->validated('email'))->first();

        // Identical failure for "no such email" and "wrong password" -
        // deliberately doesn't reveal which one. See STEP 04 plan §5.
        if (! $customer || ! Hash::check($request->validated('password'), $customer->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        // Checked only AFTER the password is confirmed correct - checking
        // status first would let an unauthenticated caller learn account
        // state without ever proving they know the password.
        if ($customer->status !== 'active') {
            throw new AuthorizationException('Your account is inactive. Please contact support.');
        }

        $token = $customer->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'customer' => $customer,
            'token' => $token,
        ], 'Login successful.');
    }

    /**
     * POST /api/v1/customer/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        // Revokes ONLY the token used for this request, not every
        // device/session, per the explicit STEP 04 requirement.
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Logged out successfully.');
    }

    /**
     * GET /api/v1/customer/me
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success($request->user(), 'OK');
    }
}
