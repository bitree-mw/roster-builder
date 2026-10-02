<?php

use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
 * Application bootstrap: routes, middleware and exception rendering.
 * - statefulApi(): the Blade client authenticates to /api/v1 with Sanctum session cookies.
 * - API and JSON requests render errors through App\Support\Api\ApiExceptionRenderer (config/api.php).
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $exception, Request $request) => app(ApiExceptionRenderer::class)($exception, $request));
    })->create();
