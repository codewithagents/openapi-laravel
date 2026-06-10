<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('registers the openapi:generate command', function () {
    expect(array_keys(Artisan::all()))->toContain('openapi:generate');
});

it('merges the package config', function () {
    expect(config('openapi-laravel.max_depth'))->toBe(64)
        ->and(config('openapi-laravel.output.namespace'))->toBe('App\\Data');
});
