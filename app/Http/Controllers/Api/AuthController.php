<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     * Body: name, email, password, password_confirmation
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'Account created successfully.',
            'data'    => [
                'user'       => $user,
                'token'      => $token,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    /**
     * POST /api/v1/auth/login
     * Body: email, password
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Optionally: delete old tokens for "single device" login
        // $user->tokens()->delete();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message'    => 'ok',
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'role'     => $user->role,
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/me
     * Header: Authorization: Bearer {token}
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'restaurant_id' => $user->restaurant_id,
                'restaurant_name' => $user->restaurant->name ?? null,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     * Revokes the current access token only.
     */
    public function logout(Request $request): JsonResponse
    {
        // revoke current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * POST /api/v1/auth/logout-all
     * Revokes all tokens for the authenticated user (all devices).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Logged out from all devices.',
        ]);
    }

    /**
     * PUT /api/v1/auth/profile
     * Body: name?, email?
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ]);

        $user->fill($data);
        $user->save();

        return response()->json([
            'status'  => 'success',
            'message' => 'Profile updated.',
            'data'    => [
                'user' => $user,
            ],
        ]);
    }

    /**
     * PUT /api/v1/auth/password
     * Body: current_password, password, password_confirmation
     */
    public function updatePassword(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        // (Optional) Invalidate all other tokens:
        // $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Password updated successfully.',
        ]);
    }

    /**
     * POST /api/v1/auth/refresh-token
     * Issue a new token and revoke the current one.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Delete current token if exists
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        $newToken = $user->createToken('api')->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'Token refreshed successfully.',
            'data'    => [
                'token'      => $newToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }
}
