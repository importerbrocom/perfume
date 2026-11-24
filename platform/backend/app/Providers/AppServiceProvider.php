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
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(180)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login-ip:'.$r->ip()),
            Limit::perHour(20)->by('login-email:'.mb_strtolower((string) $r->input('email'))),
        ]);
        RateLimiter::for('quote', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('track', fn (Request $r) => Limit::perMinutes(10, 10)->by($r->ip()));
        RateLimiter::for('reviews', fn (Request $r) => Limit::perHour(5)->by($r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));
    }
}
