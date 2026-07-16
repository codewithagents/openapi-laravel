<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use JsonSerializable;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A throwable, self-rendering carrier for a spec-declared error response.
 *
 * The generated abstract controller method types its return as the
 * operation's SUCCESS (smallest 2xx) Data class by design: error responses
 * are deliberately never inspected for typing. That leaves a real gap for a
 * concrete controller that must answer a spec-declared error status (a 404
 * `ErrorResponseData`, say): a hand-rolled helper that RETURNS a JsonResponse
 * clashes with the success return type ("Expected PetData, Found
 * JsonResponse"). THROWING never hits a `return`, so the declared success
 * type stays satisfied no matter which error path a method takes.
 *
 * ApiError closes that gap. It carries any generated Data class (or anything
 * else that implements Responsable, Arrayable, or JsonSerializable) plus an
 * HTTP status, and renders itself: Laravel's exception handler calls
 * `render(Request): Response` on any thrown exception that defines it
 * (`Illuminate\Foundation\Exceptions\Handler::render()`), before it even
 * checks Responsable, so no `bootstrap/app.php` registration is needed.
 *
 * The named factories exist so the status is written ONCE, at the throw
 * site, and never duplicated as a raw `Response::HTTP_*` argument a reader
 * has to cross-reference against the method name:
 *
 *     throw ApiError::notFound(PetErrorData::from(['message' => 'No such pet.']));
 *
 * For a status without a named factory, the general constructor stays
 * available: `throw new ApiError($body, 451);`.
 *
 * ApiError is schema-agnostic by design (issue #79 stands: the generator does
 * not generate a renderer that maps Laravel's error bag into a spec shape).
 * It is a typed CARRIER only; the caller supplies the already-generated Data
 * object (or any Responsable/Arrayable/JsonSerializable value) that matches
 * the spec's declared error schema.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public function __construct(
        public readonly Arrayable|JsonSerializable|Responsable $body,
        public readonly int $status,
    ) {
        parent::__construct(sprintf('API error: HTTP %d.', $status));
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function badRequest(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 400);
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function unauthorized(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 401);
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function forbidden(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 403);
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function notFound(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 404);
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function conflict(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 409);
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function unprocessable(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 422);
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function tooManyRequests(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 429);
    }

    /**
     * @param  Arrayable<array-key, mixed>|JsonSerializable|Responsable  $body
     */
    public static function serverError(Arrayable|JsonSerializable|Responsable $body): self
    {
        return new self($body, 500);
    }

    /**
     * Laravel calls this automatically on any thrown exception that defines
     * it (no bootstrap/app.php registration needed): the response IS the
     * spec's declared error body, at the spec's declared status.
     */
    public function render(Request $request): Response
    {
        return response()->json($this->body, $this->status);
    }
}
