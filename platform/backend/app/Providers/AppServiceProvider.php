<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // RATE_LIMIT_SCALE multiplies every limit. Keep 1 in production; the browser tests raise it
        // because all their requests come from one IP.
        $k = max(1, (int) config('shop.rate_limit_scale', 1));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(180 * $k)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5 * $k)->by('login-ip:'.$r->ip()),
            Limit::perHour(20 * $k)->by('login-email:'.mb_strtolower((string) $r->input('email'))),
        ]);
        RateLimiter::for('quote', fn (Request $r) => Limit::perMinute(60 * $k)->by($r->ip()));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(10 * $k)->by($r->ip()));
        RateLimiter::for('track', fn (Request $r) => Limit::perMinutes(10, 10 * $k)->by($r->ip()));
        RateLimiter::for('reviews', fn (Request $r) => Limit::perHour(5 * $k)->by($r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));
    }
}
