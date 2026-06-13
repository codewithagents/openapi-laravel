<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Middleware\BaseClassMarker;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Configurable controller base class for the generated abstract controllers
 * (issue #83, controllers.base_class).
 *
 * config/openapi-laravel.php sets controllers.base_class to this FQCN, so the
 * generator emits each abstract as `extends BaseApiController`. The concrete
 * controllers extend the abstracts, so this base sits at the root of every
 * generated controller's hierarchy.
 *
 * It declares the BaseClassMarker middleware via Laravel 12's HasMiddleware
 * interface. Because the generated routes dispatch to the concrete controllers
 * in the [Controller::class, 'method'] array form, controller middleware runs,
 * and the marker stamps an X-Base-Class header on every response. That header,
 * asserted over real HTTP in the e2e suite, is the observable proof that the
 * generated abstract really extends the configured base.
 */
abstract class BaseApiController implements HasMiddleware
{
    /**
     * @return array<int, Middleware|string>
     */
    public static function middleware(): array
    {
        return [BaseClassMarker::class];
    }
}
