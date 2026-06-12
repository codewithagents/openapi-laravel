<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

/**
 * One spec operation, resolved into everything the controller and route
 * generators need: the HTTP method and spec path, the controller it belongs to
 * (concrete + abstract names), the method name, the route name, typed path
 * params, the request body param (or a Request fallback), the return type, and
 * the imports the controller file must `use`.
 *
 * A plain immutable value object: just resolved data plus one derived
 * predicate (needsStatusMiddleware), so both downstream generators stay
 * trivial and deterministic.
 *
 * @internal
 */
final readonly class OperationDescriptor
{
    /**
     * `queryParam` (issue #63) names the operation's generated query Data
     * class, or null when the operation has no usable `in: query` parameters.
     * `injected` is true when the class is type-hinted into the method
     * signature (body-less operations, where container injection is safe);
     * false when the implementer must call `::fromQuery($request)` explicitly
     * (operations with a request body, where auto-injection would validate
     * the query class against the merged body + query input). `fqcn` is the
     * class's fully-qualified name, which under the tag-grouped data layout
     * (issue #93) may sit in a tag subnamespace; the non-injected docblock
     * pointer spells it out from here.
     *
     * @param  list<array{name: string, phpType: string}>  $pathParams
     * @param  array{name: string, type: string}|null  $bodyParam
     * @param  list<string>  $imports  FQCNs the controller file must `use`
     * @param  array{name: string, type: string, injected: bool, fqcn: string}|null  $queryParam
     * @param  list<string>  $securityMiddleware
     */
    public function __construct(
        public string $httpMethod,
        public string $path,
        public string $controllerClass,
        public string $abstractClass,
        public string $methodName,
        /*
         * The `->name()` every generated route carries (issue #71). It
         * follows the CHOSEN method name (the conventional Laravel name for a
         * clean RESTful operation, otherwise the operationId-derived
         * identifier, issue #94), but is made unique across the WHOLE route
         * table rather than per controller, so the route() helper can target
         * any operation unambiguously.
         */
        public string $routeName,
        public array $pathParams,
        public ?array $bodyParam,
        public bool $bodyRequiresRequest,
        public string $returnType,
        public ?string $returnDoc,
        public ?string $summary,
        public array $imports,
        public ?array $queryParam = null,
        /*
         * The numeric status code of the SELECTED success response (issue
         * #64): the same smallest-2xx pick that drives the return type. Null
         * when the selection fell through to `default` or to a non-2xx first
         * response, where no success status is actually declared.
         */
        public ?int $successStatus = null,
        /*
         * The middleware names the spec's security declarations resolve to
         * through security.middleware_map (issue #77): the operation's own
         * `security` when declared (an explicit [] means public, so this stays
         * empty), otherwise the document-level security. Already deduplicated
         * and in deterministic spec order; empty means the route carries no
         * security middleware.
         */
        public array $securityMiddleware = [],
    ) {}

    /**
     * Whether the generated route must enforce the spec-declared success
     * status via the RespondsWithStatus middleware (issue #64): a known
     * non-200 success status. Laravel's default is already 200, and a null
     * status means the spec declared none, so neither needs enforcement.
     */
    public function needsStatusMiddleware(): bool
    {
        return $this->successStatus !== null && $this->successStatus !== 200;
    }

    /**
     * The method's parameter declarations ("Type $name"), in signature order:
     * the request body (or its Request fallback), then the injected query Data
     * class (issue #63, body-less operations only), then the path parameters.
     *
     * Shared by the abstract controller emitter and the concrete stub emitter
     * (issue #78), so a scaffolded stub can never disagree with the abstract
     * signature it overrides.
     *
     * @return list<string>
     */
    public function parameterDeclarations(): array
    {
        $params = [];

        if ($this->bodyParam !== null) {
            $params[] = $this->bodyParam['type'].' $'.$this->bodyParam['name'];
        } elseif ($this->bodyRequiresRequest) {
            $params[] = 'Request $request';
        }

        if ($this->queryParam !== null && $this->queryParam['injected']) {
            $params[] = $this->queryParam['type'].' $'.$this->queryParam['name'];
        }

        foreach ($this->pathParams as $pathParam) {
            $params[] = $pathParam['phpType'].' $'.$pathParam['name'];
        }

        return $params;
    }
}
