<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Support\ApiError;

/**
 * Direct unit coverage for the ApiError carrier (the throwable, self-rendering
 * substrate the generated `<Operation>Errors` factories forward into, and a
 * documented escape hatch in its own right).
 *
 * Pure PHP, no container: render() needs the response() helper and lives in the
 * Feature-level ApiErrorRenderTest. Here we only assert the value semantics: the
 * named factories set the documented status and store the exact body, the
 * general constructor covers any other status, and it is a Throwable.
 */
final class ApiErrorUnitFakeBody implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}

it('sets the documented status and stores the exact body for each named factory', function (string $factory, int $status) {
    $body = new ApiErrorUnitFakeBody(['message' => 'x']);

    /** @var ApiError $error */
    $error = ApiError::{$factory}($body);

    expect($error->status)->toBe($status)
        ->and($error->body)->toBe($body);
})->with([
    'badRequest' => ['badRequest', 400],
    'unauthorized' => ['unauthorized', 401],
    'forbidden' => ['forbidden', 403],
    'notFound' => ['notFound', 404],
    'conflict' => ['conflict', 409],
    'unprocessable' => ['unprocessable', 422],
    'tooManyRequests' => ['tooManyRequests', 429],
    'serverError' => ['serverError', 500],
]);

it('accepts an arbitrary status through the general constructor', function () {
    $body = new ApiErrorUnitFakeBody(['message' => 'gone for legal reasons']);
    $error = new ApiError($body, 451);

    expect($error->status)->toBe(451)
        ->and($error->body)->toBe($body);
});

it('is a RuntimeException, and therefore a Throwable', function () {
    $error = ApiError::notFound(new ApiErrorUnitFakeBody([]));

    expect($error)->toBeInstanceOf(RuntimeException::class)
        ->and($error)->toBeInstanceOf(Throwable::class);
});
