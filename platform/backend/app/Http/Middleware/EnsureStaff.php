<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Logged-in, active staff account; optional role list (e.g. staff:admin). */
class EnsureStaff
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Please log in.'], 401);
        }
        if ($roles && ! in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Only an admin can do this.'], 403);
        }

        return $next($request);
    }
}
