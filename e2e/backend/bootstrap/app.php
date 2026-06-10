<?php

use App\Http\Middleware\CreatedResponse;
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
        // are the concrete controllers extending the generated abstracts. The
        // CreatedResponse middleware promotes the create operations to 201.
        then: function (): void {
            Route::prefix('api')
                ->middleware([CreatedResponse::class])
                ->group(__DIR__.'/../routes/api.generated.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
