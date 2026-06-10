<?php

use App\Http\Middleware\CreatedResponse;
use App\Http\Middleware\ForceJsonAccept;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Mount the GENERATED routes file under the /api prefix. The endpoints
        // (/api/pet, /api/pet/{petId}, ...) are real HTTP routes whose targets
        // are the concrete controllers extending the generated abstracts.
        //
        // ForceJsonAccept: the generated openapi-zod-ts client does not send an
        // Accept header, so browsers default to text/html. Without this,
        // $request->wantsJson() is false and Laravel returns 302 redirects on
        // errors instead of 422 JSON. ForceJsonAccept sets Accept:
        // application/json on every request entering this route group, making
        // all error responses JSON regardless of what the client advertised.
        //
        // CreatedResponse promotes successful create operations to 201.
        then: function (): void {
            Route::prefix('api')
                ->middleware([ForceJsonAccept::class, CreatedResponse::class])
                ->group(__DIR__.'/../routes/api.generated.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
