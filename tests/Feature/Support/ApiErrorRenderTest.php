<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feature-level coverage for ApiError::render(): it needs the response() helper,
 * so it boots Testbench (per tests/Pest.php, Feature tests get the container;
 * Unit tests do not). We call render() directly (no routing needed here): the
 * response IS the carried body, serialized, at the carried status.
 */
final class ApiErrorRenderFakeBody implements JsonSerializable
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

it('renders the carried body at the documented status for each named factory', function (string $factory, int $status) {
    $body = new ApiErrorRenderFakeBody(['message' => 'boom', 'code' => $status]);

    /** @var ApiError $error */
    $error = ApiError::{$factory}($body);
    $response = $error->render(Request::create('/x'));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe($status)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['message' => 'boom', 'code' => $status]);
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

it('renders an arbitrary status from the general constructor', function () {
    $response = (new ApiError(new ApiErrorRenderFakeBody(['detail' => 'nope']), 451))->render(Request::create('/x'));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(451)
        ->and(json_decode((string) $response->getContent(), true))->toBe(['detail' => 'nope']);
});
