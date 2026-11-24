<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\StagingGate;
use App\Support\Cloudflare;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cloudflare in front: trust its forwarded headers only when the request comes from Cloudflare.
        $middleware->trustProxies(at: array_merge(Cloudflare::RANGES, ['127.0.0.1', '::1']), headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST);
        // Admin panel: session cookie + CSRF (Sanctum SPA mode) for requests from this domain.
        $middleware->statefulApi();
        $middleware->alias(['staff' => EnsureStaff::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [StagingGate::class]);
        $middleware->api(prepend: [StagingGate::class]);
        // Stripe posts webhooks without a CSRF token; they are authenticated by signature instead.
        $middleware->validateCsrfTokens(except: ['api/webhooks/*', 'gate']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
