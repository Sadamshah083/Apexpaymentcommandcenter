<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'webhooks/morpheus/*',
        ]);

        $middleware->alias([
            'communications.admin' => \App\Http\Middleware\EnsureCommunicationsAdminAccess::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\EagerLoadAuthenticatedUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Error Monitoring UI removed — Telescope remains the exception debugger.
        // Do not write to application_errors on every exception (cuts DB write load).
    })->create();
