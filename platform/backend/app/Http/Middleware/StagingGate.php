<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private test site lock: visitors must enter SHOP_GATE_KEY once (30-day cookie) or send it as X-Shop-Gate.
 * Stripe webhooks, the admin API and the Apple Pay domain file are let through (they have their own protection).
 */
class StagingGate
{
    private const OPEN = ['api/webhooks/*', 'api/admin/*', 'admin', 'admin/*', 'sanctum/csrf-cookie', '.well-known/*', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('shop.gate_key');
        if ($key === '' || $request->is(...self::OPEN)) {
            return $next($request);
        }
        $token = hash_hmac('sha256', $key, (string) config('app.key'));
        if (hash_equals($token, (string) $request->cookie('shop_gate')) || hash_equals($key, (string) $request->header('X-Shop-Gate'))) {
            return $next($request);
        }
        if ($request->isMethod('post') && $request->is('gate') && hash_equals($key, (string) $request->input('key'))) {
            return redirect('/')->withCookie(cookie('shop_gate', $token, 60 * 24 * 30, '/', null, $request->isSecure(), true, false, 'Lax'));
        }
        if ($request->is('api/*')) {
            return response()->json(['message' => 'This shop is not public yet.', 'gate' => true], 401);
        }

        return response()->view('gate', ['error' => $request->isMethod('post')], 401)->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
