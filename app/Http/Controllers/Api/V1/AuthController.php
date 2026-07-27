<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        if ($user->activo === false) {
            return response()->json([
                'message' => 'El usuario está inactivo.',
            ], 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        $user->loadMissing('role');

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $request->user()?->loadMissing('role');

        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken === null && $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($request->bearerToken());
        }

        $accessToken?->delete();

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }
}
