<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->redirectGuestsTo(fn () => url('/admin/login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['answers', 'other_answers', 'token', 'submission_id', 'role', 'respondent', 'respondent_name', 'respondent_role', 'fields', 'contact_id', 'consent', 'anonymous']);
    })->create();
