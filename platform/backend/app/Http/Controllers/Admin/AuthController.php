<?php

namespace App\Http\Controllers\Admin;

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
    public static function me(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role];
    }

    /** POST /api/admin/login (session cookie + CSRF via Sanctum). Rate limited. */
    public function login(Request $request): JsonResponse
    {
        $v = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'remember' => 'nullable|boolean']);
        if (! Auth::guard('web')->attempt(['email' => mb_strtolower($v['email']), 'password' => $v['password'], 'is_active' => true], (bool) ($v['remember'] ?? false))) {
            throw ValidationException::withMessages(['email' => 'Wrong email or password.']);
        }
        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['user' => self::me($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => self::me($request->user())]);
    }

    public function password(Request $request): JsonResponse
    {
        $v = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $u = $request->user();
        if (! Hash::check($v['current_password'], $u->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is wrong.']);
        }
        $u->password = $v['password'];
        $u->save();
        Auth::guard('web')->logoutOtherDevices($v['password']);

        return response()->json(['ok' => true]);
    }
}
