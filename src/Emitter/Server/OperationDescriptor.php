<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

/**
 * One spec operation, resolved into everything the controller and route
 * generators need: the HTTP method and spec path, the controller it belongs to
 * (concrete + abstract names), the method name, typed path params, the request
 * body param (or a Request fallback), the return type, and the imports the
 * controller file must `use`.
 *
 * A plain immutable value object: no behaviour, just resolved data, so both
 * downstream generators stay trivial and deterministic.
 */
final class OperationDescriptor
{
    /**
     * @param  list<array{name: string, phpType: string}>  $pathParams
     * @param  array{name: string, type: string}|null  $bodyParam
     * @param  list<string>  $imports  FQCNs the controller file must `use`
     */
    public function __construct(
        public readonly string $httpMethod,
        public readonly string $path,
        public readonly string $controllerClass,
        public readonly string $abstractClass,
        public readonly string $methodName,
        public readonly array $pathParams,
        public readonly ?array $bodyParam,
        public readonly bool $bodyRequiresRequest,
        public readonly string $returnType,
        public readonly ?string $returnDoc,
        public readonly ?string $summary,
        public readonly array $imports,
    ) {}
}
