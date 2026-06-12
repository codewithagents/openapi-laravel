<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Spec;

/**
 * The Responses Object of an operation (issue #104): a map from status code
 * key (`"200"`, `"2XX"`, `"default"`) to its response. Range keys and
 * `default` survive verbatim; purely numeric keys are coerced to int by PHP's
 * array key canonicalization (`"200"` becomes `200`), which is why the key
 * type is `int|string` and consumers should go through `has()` / `get()` or
 * `statusCodes()`, which always speak strings.
 *
 * @internal
 */
final readonly class ResponsesNode
{
    /**
     * @param  array<int|string, ResponseNode|ReferenceNode>  $responses  status code key to response
     * @param  array<string, mixed>  $extensions  `x-*` vendor extension keys
     */
    public function __construct(
        public array $responses = [],
        public array $extensions = [],
    ) {}

    public function has(string $statusCode): bool
    {
        return array_key_exists($statusCode, $this->responses);
    }

    public function get(string $statusCode): ResponseNode|ReferenceNode|null
    {
        return $this->responses[$statusCode] ?? null;
    }

    /**
     * The status code keys as strings, in spec order.
     *
     * @return list<string>
     */
    public function statusCodes(): array
    {
        return array_map(strval(...), array_keys($this->responses));
    }
}
