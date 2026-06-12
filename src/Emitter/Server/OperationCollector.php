<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\ResolvedClosure;
use CodeWithAgents\OpenApiLaravel\Emitter\SchemaPointer;
use CodeWithAgents\OpenApiLaravel\Emitter\TagGroups;
use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\MediaTypeNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OperationNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ParameterNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\PathItemNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\RequestBodyNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponseNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponsesNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Walks an OpenAPI document and resolves each operation into an
 * {@see OperationDescriptor}, using the model generator's registry to type
 * request bodies and responses against the already-generated Data classes.
 *
 * Robustness is a hard requirement: a missing operationId, absent tags, no
 * responses, non-JSON bodies, weird path tokens, and unresolved $refs must
 * never fatal. An inline JSON object body synthesizes a per-operation Data
 * class through the model generator (issue #76), and a multipart/form-data
 * object body does the same with UploadedFile typing for its binary parts
 * (issue #75); when a type cannot be derived the collector falls back to
 * injecting Request / returning JsonResponse, and every such fallback is
 * surfaced through the warnings channel (issue #67) so the degradation is
 * visible at generation time.
 *
 * Output is deterministic: descriptors are sorted by path, then by a fixed
 * HTTP-method order, and method names are made unique per controller.
 *
 * @internal
 */
final class OperationCollector
{
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    /**
     * The valid Responses Object keys per the OpenAPI spec: `default`, a
     * concrete status code, or an uppercase range wildcard (`2XX`). The old
     * cebe object model enforced exactly this pattern at hydration time
     * (dropping invalid keys with a validation note); the typed graph keeps
     * every key, so the collector applies the same filter itself to stay
     * byte-identical, see {@see successResponse()}.
     */
    private const RESPONSE_KEY_PATTERN = '~^(?:default|[1-5](?:[0-9][0-9]|XX))$~';

    private const REQUEST_FQCN = 'Illuminate\\Http\\Request';

    private const JSON_RESPONSE_FQCN = 'Illuminate\\Http\\JsonResponse';

    private const JSON_RESPONSE_SHORT = 'JsonResponse';

    private const DATA_COLLECTION_FQCN = 'Spatie\\LaravelData\\DataCollection';

    private const DATA_COLLECTION_SHORT = 'DataCollection';

    /**
     * @param  array<string, array{dataClass: string, writeClass: ?string, kind: 'data'|'enum'}>  $registry
     */
    public function __construct(
        private readonly ServerOptions $options,
        private readonly array $registry,
        /*
         * Subset generation (issue #44). When non-null, only operations the
         * closure kept (by path + method) become controllers/routes, so a
         * tag-scoped run does not scaffold the whole API. Null (the default)
         * keeps every operation, byte-identical to a full run.
         */
        private readonly ?ResolvedClosure $closure = null,
        /*
         * Query-parameter (issue #63) and inline-body (issue #76) support.
         * When non-null, each operation's `in: query` parameters are turned
         * into a per-operation query Data class, and an inline JSON object
         * request body into a per-operation body Data class, both through the
         * model generator's rules pipeline (the generator must already have
         * run generate() for this document). Null (the default) skips both,
         * keeping legacy call sites and tests byte-identical.
         */
        private readonly ?ModelGenerator $models = null,
    ) {}

    /**
     * Non-fatal diagnostics from the last collect() run, sorted for
     * determinism: header/cookie parameters the scaffold does not generate
     * yet, plus every silent degradation the collection had to take (issue
     * #67): a body or response that fell back to Request/JsonResponse over an
     * unresolved or unsupported $ref, a non-object inline or non-JSON body,
     * and a dropped parameter $ref. Keyed by message so a path-level parameter
     * shared across the operations of one path is reported once per
     * operation, never duplicated across re-collections. Mirrors
     * ModelGenerator::warnings().
     *
     * @var array<string, true>
     */
    private array $warnings = [];

    /**
     * The document's `components.requestBodies` map for the current collect()
     * run (issue #110), keyed by component name, so an operation whose
     * requestBody is a `$ref` resolves through the same content-type routing
     * an inline body takes. Only direct RequestBody entries are kept: a
     * ref-to-ref chain inside the components stays unresolved and degrades
     * like any other unresolvable ref, mirroring componentParameters().
     *
     * @var array<string, RequestBodyNode>
     */
    private array $componentRequestBodies = [];

    /**
     * Component requestBody name => the single first tag shared by EVERY
     * operation in the document that references it, or null when the
     * referencing operations span different tag groups (or are tagless). The
     * shared body class follows that single group under the tag-grouped
     * layout (issue #93) and stays at the flat root otherwise, mirroring how
     * a schema reachable from several tag groups stays at the root. Computed
     * over the whole document (not the subset closure) so the placement is
     * stable across subset runs, exactly like the schema attribution walk.
     *
     * @var array<string, ?string>
     */
    private array $componentBodyTags = [];

    /**
     * The document's `components.responses` map for the current collect()
     * run (issue #111), keyed by component name, so an operation whose
     * selected success response is a `$ref` resolves through the same
     * content-type routing an inline response takes. Only direct Response
     * entries are kept: a ref-to-ref chain inside the components stays
     * unresolved and degrades like any other unresolvable ref, mirroring
     * componentRequestBodies.
     *
     * @var array<string, ResponseNode>
     */
    private array $componentResponses = [];

    /**
     * Component response name => the single first tag shared by EVERY
     * operation in the document that references it, or null when the
     * referencing operations span different tag groups (or are tagless). The
     * shared response class follows that single group under the tag-grouped
     * layout (issue #93) and stays at the flat root otherwise, exactly like
     * the component requestBody attribution (issue #110). Computed over the
     * whole document (not the subset closure) so the placement is stable
     * across subset runs.
     *
     * @var array<string, ?string>
     */
    private array $componentResponseTags = [];

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = array_keys($this->warnings);
        sort($warnings);

        return $warnings;
    }

    /**
     * @return list<OperationDescriptor>
     */
    public function collect(OpenApiDocument $document): array
    {
        $this->warnings = [];
        $componentParameters = $this->componentParameters($document);
        $this->componentRequestBodies = $this->collectComponentRequestBodies($document);
        $this->componentBodyTags = $this->collectComponentBodyTags($document);
        $this->componentResponses = $this->collectComponentResponses($document);
        $this->componentResponseTags = $this->collectComponentResponseTags($document);

        // Security-to-middleware resolution (issue #77): one resolver per run,
        // seeded with the configured map and the document-level security so
        // every operation resolves against the same state. The typo check
        // (a mapped scheme the spec never declares) runs once, up front.
        // Document-level null (no `security` key) and the explicit empty list
        // both seed an empty global state, exactly as before.
        $security = new SecurityMiddlewareResolver(
            $this->options->securityMiddlewareMap,
            $document->security ?? [],
        );
        $security->warnUndeclaredMappings($this->declaredSchemeNames($document));

        $rows = [];

        foreach ($document->paths as $path => $pathItem) {
            // PHP canonicalizes a numeric-string array key to int; the path
            // must stay a string for the descriptor and the sort below.
            $path = (string) $path;

            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem->{$method} ?? null;

                if (! $operation instanceof OperationNode) {
                    continue;
                }

                // Subset generation (issue #44): when a closure is configured,
                // emit only the operations it kept. A null closure (the default)
                // keeps every operation, byte-identical to a full run.
                if ($this->closure !== null && ! $this->closure->keepsOperation($method, $path)) {
                    continue;
                }

                $rows[] = [
                    'path' => $path,
                    'method' => $method,
                    'operation' => $operation,
                    'parameters' => $this->mergedParameters($pathItem, $operation, $componentParameters, strtoupper($method).' '.$path),
                ];
            }
        }

        // Deterministic order: path first, then fixed HTTP-method order.
        usort($rows, function (array $a, array $b): int {
            $byPath = strcmp($a['path'], $b['path']);
            if ($byPath !== 0) {
                return $byPath;
            }

            return $this->methodRank($a['method']) <=> $this->methodRank($b['method']);
        });

        // Per-controller name allocators so two operations on one controller
        // never collide, while identical method names across controllers stay.
        /** @var array<string, UniqueNames> $methodNames */
        $methodNames = [];

        // Route names share the method-name identifier but must be unique
        // across the WHOLE route table (Laravel resolves route() globally), so
        // they get one global allocator instead of the per-controller ones.
        $routeNames = new UniqueNames;

        // Laravel-convention method names (issue #94, the only naming):
        // resolve each operation's conventional candidate BEFORE the
        // descriptors are built, so the per-controller ambiguity rule (a
        // conventional name claimed by more than one operation in the same
        // controller makes ALL claimants fall back) is decided over the whole
        // controller at once, independently of descriptor order.
        $conventional = $this->conventionalNames($rows);

        $descriptors = [];
        foreach ($rows as $index => $row) {
            $descriptors[] = $this->describe($row['path'], $row['method'], $row['operation'], $row['parameters'], $methodNames, $routeNames, $security, $conventional[$index]);
        }

        // The resolver's warnings (unmapped schemes, OR alternatives, mapped
        // typos) join the collector's channel, already keyed by message so a
        // global scheme shared by every operation reports once.
        $this->warnings += $security->warnings();

        return $descriptors;
    }

    /**
     * The conventional Laravel method name (issue #94) each row's descriptor
     * should try first, aligned with $rows by index. Null for an operation
     * that gets no conventional name (non-CRUD, or an ambiguous claim), which
     * falls back to its operationId-derived name.
     *
     * Two passes so the result is order-independent: first every row's raw
     * candidate is counted per (controller, name) pair, then a candidate
     * claimed by more than one operation in the SAME controller is withdrawn
     * for all claimants (e.g. two collection GETs under one tag would both be
     * `index`, so both keep their operationId-derived name instead). The same
     * conventional name in different controllers is fine.
     *
     * @param  list<array{path: string, method: string, operation: OperationNode, parameters: list<ParameterNode>}>  $rows
     * @return array<int, ?string> aligned with $rows by index
     */
    private function conventionalNames(array $rows): array
    {
        $candidates = [];
        $claims = [];
        foreach ($rows as $index => $row) {
            $candidate = LaravelConventionNames::candidate($row['method'], $row['path']);
            $candidates[$index] = $candidate;

            if ($candidate !== null) {
                // Keyed by the resolved controller CLASS, not the raw tag: two
                // tags that normalize to the same class ('pet store' and
                // 'PetStore') share one controller, so they must share one
                // conventional-name budget too.
                $key = $this->controllerClass($row['operation']).' '.$candidate;
                $claims[$key] = ($claims[$key] ?? 0) + 1;
            }
        }

        foreach ($rows as $index => $row) {
            $candidate = $candidates[$index];
            if ($candidate !== null && $claims[$this->controllerClass($row['operation']).' '.$candidate] > 1) {
                $candidates[$index] = null;
            }
        }

        return $candidates;
    }

    /**
     * The controller class an operation belongs to, derived from its first
     * tag. The single naming point shared by describe() and the
     * conventional-name resolution, so the two can never disagree about which
     * controller an operation lands in.
     */
    private function controllerClass(OperationNode $operation): string
    {
        return PhpIdentifier::toClassName($this->firstTag($operation)).$this->options->controllerSuffix;
    }

    /**
     * @param  list<ParameterNode>  $parameters
     * @param  array<string, UniqueNames>  $methodNames
     */
    private function describe(string $path, string $method, OperationNode $operation, array $parameters, array &$methodNames, UniqueNames $routeNames, SecurityMiddlewareResolver $security, ?string $conventionalName): OperationDescriptor
    {
        $controllerClass = $this->controllerClass($operation);
        $abstractClass = $this->options->abstractPrefix.$controllerClass;

        if (! isset($methodNames[$controllerClass])) {
            $methodNames[$controllerClass] = new UniqueNames;
        }

        // The unambiguous conventional name (issue #94) wins over the
        // operationId-derived one; any residual clash inside the controller
        // (say, another operation whose operationId is literally `index`)
        // still goes through the per-controller allocator and gets suffixed.
        $methodName = $methodNames[$controllerClass]->reserve($conventionalName ?? $this->methodName($operation, $method, $path));

        // The route name reuses the (already per-controller-unique) method
        // name as its candidate, then the global allocator suffixes any
        // cross-controller clash (_2, _3, ...). Deterministic because the
        // descriptor order is. This deliberately follows the CHOSEN method
        // name (issue #94), so the routes file reads conventionally too; with
        // several controllers each owning an `index`, the later ones become
        // index_2, index_3, ...
        $routeName = $routeNames->reserve($methodName);

        $pathParams = $this->pathParams($path, $parameters);
        $imports = [];

        // "GET /pets", the operation label every degradation warning leads with.
        $label = strtoupper($method).' '.$path;

        // The StudlyCaps operation context an inline body class is named from
        // (issue #76), the same operationId-or-fallback the query class uses,
        // so `addPet` yields AddPetRequestData next to AddPetQueryData.
        // Deliberately NOT the conventional method name (issue #94), exactly
        // like the query classes: Data classes share one global namespace,
        // and a per-controller `StoreRequestData` would clash across
        // controllers while CreatePetRequestData stays unique.
        $bodyBaseName = PhpIdentifier::toClassName($this->methodName($operation, $method, $path));

        [$bodyParam, $bodyRequiresRequest] = $this->requestBody($operation, $imports, $label, $bodyBaseName, $pathParams);
        [$returnType, $returnDoc, $successStatus] = $this->responseType($operation, $imports, $label);

        $this->warnUnsupportedParameterLocations($method, $path, $parameters);
        $queryParam = $this->queryParam($method, $path, $operation, $parameters, $bodyParam, $bodyRequiresRequest, $pathParams, $imports);

        sort($imports);
        $imports = array_values(array_unique($imports));

        // A known non-200 success status is enforced by the generated route
        // middleware (issue #64), so the RespondsWithStatus support class must
        // be inlined into the consumer's Support namespace alongside the rule
        // classes. Recording it here (the single collection point) keeps
        // generate and check in lockstep through the shared planner.
        if ($successStatus !== null && $successStatus !== 200) {
            $this->models?->markSupportClassUsed('RespondsWithStatus');
        }

        return new OperationDescriptor(
            httpMethod: $method,
            path: $path,
            controllerClass: $controllerClass,
            abstractClass: $abstractClass,
            methodName: $methodName,
            routeName: $routeName,
            pathParams: $pathParams,
            bodyParam: $bodyParam,
            bodyRequiresRequest: $bodyRequiresRequest,
            returnType: $returnType,
            returnDoc: $returnDoc,
            summary: $this->summary($operation),
            imports: $imports,
            queryParam: $queryParam,
            successStatus: $successStatus,
            securityMiddleware: $security->middlewareFor($label, $operation->security),
        );
    }

    /**
     * The scheme names `components.securitySchemes` declares. Only the names
     * matter for the middleware map; the scheme objects themselves (or
     * unresolved $refs to them) are never inspected.
     *
     * @return list<string>
     */
    private function declaredSchemeNames(OpenApiDocument $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $names = [];
        foreach (array_keys($components->securitySchemes) as $name) {
            $names[] = (string) $name;
        }

        return $names;
    }

    /**
     * Resolve the operation's `in: query` parameters into a generated query
     * Data class descriptor entry (issue #63), or null when there is nothing
     * to generate (no query params, no model generator wired in, or every
     * parameter was skipped as un-serializable).
     *
     * The hybrid injection rule: a body-less operation gets the class
     * type-hinted into its signature (laravel-data resolves it from the
     * container, and the generated `fromQuery` magic creation method hydrates
     * it from the query string only). An operation WITH a request body (a
     * typed Data param or the Request fallback) must NOT auto-inject:
     * container resolution would hand the query class the merged body + query
     * input. Those operations get a docblock pointer to `::fromQuery($request)`
     * instead, and the class import is only added when it appears in the
     * signature, so no `use` goes unused.
     *
     * @param  list<ParameterNode>  $parameters
     * @param  array{name: string, type: string}|null  $bodyParam
     * @param  list<array{name: string, phpType: string}>  $pathParams
     * @param  list<string>  $imports
     * @return array{name: string, type: string, injected: bool, fqcn: string}|null
     */
    private function queryParam(string $method, string $path, OperationNode $operation, array $parameters, ?array $bodyParam, bool $bodyRequiresRequest, array $pathParams, array &$imports): ?array
    {
        if ($this->models === null) {
            return null;
        }

        $queryParameters = [];
        foreach ($parameters as $parameter) {
            if ($parameter->in === 'query') {
                $queryParameters[] = $parameter;
            }
        }

        if ($queryParameters === []) {
            return null;
        }

        // The class name derives from the operationId-or-fallback, so
        // `findPetsByStatus` yields FindPetsByStatusQueryData. Deliberately
        // NOT the conventional method name (issue #94): query classes live in
        // the global Data namespace, where an `IndexQueryData` would clash
        // across controllers, so the Data layer stays operationId-derived.
        $baseName = PhpIdentifier::toClassName($this->methodName($operation, $method, $path));
        $label = strtoupper($method).' '.$path;

        // The operation's first tag rides along so the grouped data layout
        // (issue #93) can place the query class in its operation's tag group;
        // the flat layout ignores it. Since issue #104 the whole pipeline
        // speaks the typed spec graph, so the parameters pass through as-is.
        $class = $this->models->generateQueryData($baseName, $label, $queryParameters, $this->firstTag($operation));
        if ($class === null) {
            return null;
        }

        $injected = $bodyParam === null && ! $bodyRequiresRequest;
        if ($injected) {
            $imports[] = $this->dataFqcn($class);
        }

        // `$query` unless a body or path parameter already took that name.
        $taken = new UniqueNames;
        if ($bodyParam !== null) {
            $taken->reserve($bodyParam['name']);
        }
        if ($bodyRequiresRequest) {
            $taken->reserve('request');
        }
        foreach ($pathParams as $pathParameter) {
            $taken->reserve($pathParameter['name']);
        }

        // The FQCN rides along for the non-injected docblock pointer: under
        // the grouped layout (issue #93) the class may live in a tag
        // subnamespace, and only the collector knows which.
        return ['name' => $taken->reserve('query'), 'type' => $class, 'injected' => $injected, 'fqcn' => $this->dataFqcn($class)];
    }

    /**
     * Record a warning for each `in: header` / `in: cookie` parameter group on
     * an operation: the scaffold does not generate typing or validation for
     * those locations yet (issue #63 scoped them out), and silence would hide
     * the information loss. One warning per location kind per operation, with
     * the parameter names listed, so a spec-wide trace header does not flood
     * the channel.
     *
     * @param  list<ParameterNode>  $parameters
     */
    private function warnUnsupportedParameterLocations(string $method, string $path, array $parameters): void
    {
        foreach (['header', 'cookie'] as $kind) {
            $names = [];
            foreach ($parameters as $parameter) {
                if ($parameter->in === $kind && $parameter->name !== '') {
                    $names[] = '"'.$parameter->name.'"';
                }
            }

            if ($names !== []) {
                $this->warnings[sprintf(
                    'Operation %s %s: %s parameter(s) %s are not generated (%s parameters are not supported yet).',
                    strtoupper($method),
                    $path,
                    $kind,
                    implode(', ', $names),
                    $kind,
                )] = true;
            }
        }
    }

    private function firstTag(OperationNode $operation): string
    {
        foreach ($operation->tags as $tag) {
            if (trim($tag) !== '') {
                return $tag;
            }
        }

        // 'Untagged' is not a reserved word, so it survives toClassName intact
        // (unlike 'Default', which would escape to _Default).
        return 'Untagged';
    }

    private function methodName(OperationNode $operation, string $method, string $path): string
    {
        $operationId = $operation->operationId;

        if ($operationId !== null && trim($operationId) !== '') {
            return PhpIdentifier::toPropertyName($operationId);
        }

        // Derive deterministically from method + path, e.g. /pets/{petId} -> getPetsByPetId.
        $segments = [$method];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $token = $this->pathToken($segment);
            if ($token !== null) {
                $segments[] = 'by';
                $segments[] = $token;
            } else {
                $segments[] = $segment;
            }
        }

        return PhpIdentifier::toPropertyName(implode(' ', $segments));
    }

    /**
     * @param  list<ParameterNode>  $parameters
     * @return list<array{name: string, phpType: string}>
     */
    private function pathParams(string $path, array $parameters): array
    {
        $types = $this->pathParamTypes($parameters);
        $params = [];
        $seen = [];

        foreach (explode('/', $path) as $segment) {
            $token = $this->pathToken($segment);

            if ($token === null || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;

            $phpType = ($types[$token] ?? null) === 'integer' ? 'int' : 'string';
            $params[] = ['name' => PhpIdentifier::toPropertyName($token), 'phpType' => $phpType];
        }

        return $params;
    }

    /**
     * Spec parameter name => declared scalar type, for `in: path` parameters.
     *
     * @param  list<ParameterNode>  $parameters
     * @return array<string, string>
     */
    private function pathParamTypes(array $parameters): array
    {
        $types = [];

        foreach ($parameters as $parameter) {
            if ($parameter->in !== 'path') {
                continue;
            }

            if ($parameter->name === '') {
                continue;
            }

            $schema = $parameter->schema;
            if ($schema instanceof SchemaNode) {
                $type = $this->scalarType($schema);
                if ($type !== null) {
                    $types[$parameter->name] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * The operation-effective parameter list per OpenAPI precedence: PathItem
     * level parameters apply to every operation under the path, and an
     * operation-level parameter overrides a path-level one with the same
     * (name, in) pair. `$ref`s into `components.parameters` are resolved
     * before the merge. An unresolvable or external `$ref` entry is skipped
     * with a warning naming the pointer (issue #67).
     *
     * This is the single collection point for parameters of every `in` kind,
     * so the query-parameter support (#63) reuses it as-is.
     *
     * @param  array<string, ParameterNode>  $componentParameters
     * @param  string  $label  "GET /pets", for warning messages
     * @return list<ParameterNode>
     */
    private function mergedParameters(PathItemNode $pathItem, OperationNode $operation, array $componentParameters, string $label): array
    {
        $byKey = [];

        // Path level first, operation level second: a later write to the same
        // (in, name) key replaces the earlier one, which is exactly the
        // override the OpenAPI spec mandates.
        foreach ([$pathItem->parameters, $operation->parameters] as $level) {
            foreach ($level as $candidate) {
                $parameter = $this->resolveParameter($candidate, $componentParameters, $label);

                if ($parameter === null) {
                    continue;
                }

                if ($parameter->name === '' || $parameter->in === '') {
                    continue;
                }

                $byKey[$parameter->in.' '.$parameter->name] = $parameter;
            }
        }

        return array_values($byKey);
    }

    /**
     * Resolve one parameter entry, warning when a `$ref` degrades (issue #67):
     * an external or non-parameters pointer, or a pointer to a component that
     * does not exist (or is itself an unresolved ref-to-ref chain), drops the
     * parameter from the operation, which would otherwise be silent.
     *
     * @param  array<string, ParameterNode>  $componentParameters
     * @param  string  $label  "GET /pets", for warning messages
     */
    private function resolveParameter(ParameterNode|ReferenceNode $candidate, array $componentParameters, string $label): ?ParameterNode
    {
        if ($candidate instanceof ParameterNode) {
            return $candidate;
        }

        $pointer = $candidate->pointer();
        $name = SchemaPointer::componentName($pointer, 'parameters');

        if ($name === null) {
            $this->warnings[sprintf(
                'Operation %s: parameter $ref "%s" is external or not a #/components/parameters pointer; the parameter is ignored.',
                $label,
                $pointer,
            )] = true;

            return null;
        }

        $parameter = $componentParameters[$name] ?? null;

        if ($parameter === null) {
            $this->warnings[sprintf(
                'Operation %s: parameter $ref "%s" does not resolve to a component parameter; the parameter is ignored.',
                $label,
                $pointer,
            )] = true;
        }

        return $parameter;
    }

    /**
     * The document's `components.parameters` map, keyed by component name.
     * Only direct Parameter entries are kept: a ref-to-ref chain inside the
     * components is left unresolved and degrades like any other unresolvable
     * ref.
     *
     * @return array<string, ParameterNode>
     */
    private function componentParameters(OpenApiDocument $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $result = [];
        foreach ($components->parameters as $name => $parameter) {
            if (is_string($name) && $name !== '' && $parameter instanceof ParameterNode) {
                $result[$name] = $parameter;
            }
        }

        return $result;
    }

    /**
     * The document's `components.requestBodies` map, keyed by component name
     * (issue #110). Only direct RequestBody entries are kept: a ref-to-ref
     * chain inside the components is left unresolved and degrades like any
     * other unresolvable ref, mirroring {@see componentParameters()}.
     *
     * @return array<string, RequestBodyNode>
     */
    private function collectComponentRequestBodies(OpenApiDocument $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $result = [];
        foreach ($components->requestBodies as $name => $body) {
            if (is_string($name) && $name !== '' && $body instanceof RequestBodyNode) {
                $result[$name] = $body;
            }
        }

        return $result;
    }

    /**
     * Attribute each component requestBody to the single tag its referencing
     * operations share, or null when they span different tag groups (issue
     * #110, mirroring the multi-group rule of the tag-grouped data layout,
     * issue #93). Walks every operation in the document, not the subset
     * closure, so a component body's placement is stable across subset runs,
     * exactly like the schema attribution walk. Tags are compared by their
     * GROUP ('pet store' and 'PetStore' normalize to one group, so they agree)
     * and the first tag seen is kept as the representative.
     *
     * @return array<string, ?string>
     */
    private function collectComponentBodyTags(OpenApiDocument $document): array
    {
        $tags = [];
        $groups = [];

        foreach ($document->paths as $pathItem) {
            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem->{$method} ?? null;
                if (! $operation instanceof OperationNode) {
                    continue;
                }

                $body = $operation->requestBody;
                if (! $body instanceof ReferenceNode) {
                    continue;
                }

                $name = SchemaPointer::componentName($body->pointer(), 'requestBodies');
                if ($name === null || ! isset($this->componentRequestBodies[$name])) {
                    continue;
                }

                $tag = $this->firstTag($operation);
                $group = TagGroups::forTag($tag);

                if (! array_key_exists($name, $groups)) {
                    $groups[$name] = $group;
                    $tags[$name] = $tag;
                } elseif ($groups[$name] !== $group) {
                    // A second referencing operation in a DIFFERENT tag group:
                    // the shared class belongs to no single group, flat root.
                    $tags[$name] = null;
                }
            }
        }

        return $tags;
    }

    /**
     * The document's `components.responses` map, keyed by component name
     * (issue #111). Only direct Response entries are kept: a ref-to-ref
     * chain inside the components is left unresolved and degrades like any
     * other unresolvable ref, mirroring {@see collectComponentRequestBodies()}.
     *
     * @return array<string, ResponseNode>
     */
    private function collectComponentResponses(OpenApiDocument $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $result = [];
        foreach ($components->responses as $name => $response) {
            if (is_string($name) && $name !== '' && $response instanceof ResponseNode) {
                $result[$name] = $response;
            }
        }

        return $result;
    }

    /**
     * Attribute each component response to the single tag its referencing
     * operations share, or null when they span different tag groups (issue
     * #111, mirroring {@see collectComponentBodyTags()} exactly). Walks every
     * `$ref` entry in every operation's responses map across the whole
     * document (not the subset closure, and not only the selected success
     * response), so a component response's placement is stable across subset
     * runs and across selection changes. Tags are compared by their GROUP and
     * the first tag seen is kept as the representative.
     *
     * @return array<string, ?string>
     */
    private function collectComponentResponseTags(OpenApiDocument $document): array
    {
        $tags = [];
        $groups = [];

        foreach ($document->paths as $pathItem) {
            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem->{$method} ?? null;
                if (! $operation instanceof OperationNode) {
                    continue;
                }

                $responses = $operation->responses;
                if (! $responses instanceof ResponsesNode) {
                    continue;
                }

                foreach ($responses->responses as $response) {
                    if (! $response instanceof ReferenceNode) {
                        continue;
                    }

                    $name = SchemaPointer::componentName($response->pointer(), 'responses');
                    if ($name === null || ! isset($this->componentResponses[$name])) {
                        continue;
                    }

                    $tag = $this->firstTag($operation);
                    $group = TagGroups::forTag($tag);

                    if (! array_key_exists($name, $groups)) {
                        $groups[$name] = $group;
                        $tags[$name] = $tag;
                    } elseif ($groups[$name] !== $group) {
                        // A second referencing operation in a DIFFERENT tag
                        // group: the shared class belongs to no single group,
                        // flat root.
                        $tags[$name] = null;
                    }
                }
            }
        }

        return $tags;
    }

    /**
     * Resolve the request body into a typed Data param, or fall back to
     * injecting `Illuminate\Http\Request`. Every fallback emits a warning
     * naming the operation and the cause (issue #67): the fallback compiles
     * and runs, but it silently drops the spec-declared body validation,
     * which a user should learn at generation time.
     *
     * A schema-level `$ref` body is typed against the registry; an INLINE
     * object body (issue #76) synthesizes a per-operation Data class
     * (`<Operation>RequestData`) through the model generator's emission
     * pipeline, so the inline shape gets the same rules() and typed param a
     * component schema would. A multipart/form-data object body (issue #75,
     * inline or `$ref`) synthesizes the same per-operation class with
     * UploadedFile typing for its binary file parts; JSON wins when an
     * operation declares both media types. A body that is itself a `$ref` to
     * `#/components/requestBodies/<Name>` (issue #110) resolves to the
     * component and routes through exactly this logic, with one twist: a
     * synthesized class is SHARED across every operation referencing the
     * component and named after it (`<Component>RequestData`), and a content
     * schema that is a `$ref` to a component schema reuses that component's
     * existing Data class like any schema-level `$ref` body.
     *
     * @param  list<string>  $imports
     * @param  string  $label  "GET /pets", for warning messages
     * @param  string  $bodyBaseName  StudlyCaps operation context for the synthesized body class name
     * @param  list<array{name: string, phpType: string}>  $pathParams
     * @return array{0: array{name: string, type: string}|null, 1: bool}
     */
    private function requestBody(OperationNode $operation, array &$imports, string $label, string $bodyBaseName, array $pathParams): array
    {
        $body = $operation->requestBody;

        // A `$ref` body resolves against components.requestBodies (issue
        // #110) and then routes through the SAME content-type logic an inline
        // body takes: JSON object => typed Data param, multipart object =>
        // typed Data param with UploadedFile parts, non-object shapes keep
        // the warned Request fallback. The component name is carried so the
        // synthesis emits ONE shared class per component instead of one per
        // referencing operation.
        $componentBodyName = null;
        if ($body instanceof ReferenceNode) {
            $pointer = $body->pointer();
            $componentBodyName = SchemaPointer::componentName($pointer, 'requestBodies');
            $resolved = $componentBodyName !== null
                ? ($this->componentRequestBodies[$componentBodyName] ?? null)
                : null;

            if ($resolved === null) {
                // External pointer, a non-requestBodies pointer, a missing
                // component, or a ref-to-ref chain: nothing to inspect, so the
                // documented Request fallback stays. The import must be pushed
                // too, exactly like the other requiresRequest paths below, or
                // the generated controller references Request without a
                // matching use statement.
                $this->warnings[sprintf(
                    'Operation %s: the request body $ref ("%s") does not resolve to a component request body; the controller method falls back to Illuminate\Http\Request.',
                    $label,
                    $pointer,
                )] = true;
                $imports[] = self::REQUEST_FQCN;

                return [null, true];
            }

            $body = $resolved;
        }

        if (! $body instanceof RequestBodyNode) {
            return [null, false];
        }

        $schema = $this->jsonSchema($body->content);

        if ($schema === null) {
            // No JSON content: a multipart/form-data object body (issue #75)
            // synthesizes its own per-operation Data class with UploadedFile
            // parts. Consulted only after jsonSchema() found nothing, so an
            // operation declaring BOTH keeps the JSON typing (documented
            // precedence: the scaffold validates one body shape, and JSON is
            // the established, richer mapping).
            $multipart = $this->multipartSchema($body->content);

            if ($multipart !== null) {
                if ($this->models !== null) {
                    // The operation's first tag rides along so the grouped
                    // data layout (issue #93) can place the multipart body
                    // class in its operation's tag group; the flat layout
                    // ignores it. A component body (issue #110) emits ONE
                    // shared class named after the component instead, placed
                    // in the single tag group its referencing operations
                    // share (or the flat root when they span groups).
                    $class = $componentBodyName !== null
                        ? $this->models->generateComponentMultipartBodyData($componentBodyName, $label, $multipart, $this->componentBodyTags[$componentBodyName] ?? null)
                        : $this->models->generateMultipartBodyData($bodyBaseName, $label, $multipart, $this->firstTag($operation));

                    if ($class !== null) {
                        $imports[] = $this->dataFqcn($class);

                        return [['name' => $this->bodyParamName($pathParams), 'type' => $class], false];
                    }
                    // The generator already warned (a non-object shape or an
                    // unresolvable $ref); keep the documented Request fallback.
                } else {
                    // Legacy wiring without a model generator (internal call
                    // sites and tests only; the planner always wires one in).
                    $this->warnings[sprintf(
                        'Operation %s: the request body is multipart/form-data and no model generator is wired in to synthesize a Data class; the controller method falls back to Illuminate\Http\Request.',
                        $label,
                    )] = true;
                }

                $imports[] = self::REQUEST_FQCN;

                return [null, true];
            }

            // Body exists but no schema this generator types (octet-stream,
            // form-urlencoded, etc.): fall back to injecting Request.
            if ($body->content !== []) {
                $this->warnings[sprintf(
                    'Operation %s: the request body declares no application/json or multipart/form-data schema; no body validation is generated and the controller method falls back to Illuminate\Http\Request.',
                    $label,
                )] = true;
                $imports[] = self::REQUEST_FQCN;

                return [null, true];
            }

            return [null, false];
        }

        if ($schema instanceof ReferenceNode) {
            $pointer = $schema->pointer();
            $name = SchemaPointer::refName($pointer);
            if ($name !== null && isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'data') {
                $entry = $this->registry[$name];
                $type = $entry['writeClass'] ?? $entry['dataClass'];
                $imports[] = $this->dataFqcn($type);

                return [['name' => PhpIdentifier::toPropertyName($name), 'type' => $type], false];
            }

            $this->warnings[$name === null
                ? sprintf(
                    'Operation %s: the request body $ref "%s" is external or not a #/components/schemas pointer; the controller method falls back to Illuminate\Http\Request.',
                    $label,
                    $pointer,
                )
                : sprintf(
                    'Operation %s: the request body $ref "%s" does not resolve to a generated Data class; the controller method falls back to Illuminate\Http\Request.',
                    $label,
                    $pointer,
                )] = true;
        } elseif ($this->models !== null) {
            // Inline JSON body (issue #76): synthesize a per-operation Data
            // class through the model generator's emission pipeline. Only an
            // object shape produces a class (mirroring the $ref path, which
            // types only generated Data classes); a non-object inline schema
            // returns null with a warning through the generator's channel and
            // keeps the Request fallback below.
            // The operation's first tag rides along so the grouped data
            // layout (issue #93) can place the body class (and its nested
            // classes) in its operation's tag group; the flat layout
            // ignores it. A component body (issue #110) emits ONE shared
            // class named after the component instead, placed in the single
            // tag group its referencing operations share (or the flat root
            // when they span groups).
            $class = $componentBodyName !== null
                ? $this->models->generateComponentBodyData($componentBodyName, $label, $schema, $this->componentBodyTags[$componentBodyName] ?? null)
                : $this->models->generateBodyData($bodyBaseName, $label, $schema, $this->firstTag($operation));

            if ($class !== null) {
                $imports[] = $this->dataFqcn($class);

                return [['name' => $this->bodyParamName($pathParams), 'type' => $class], false];
            }
        } else {
            // Legacy wiring without a model generator (internal call sites and
            // tests only; the planner always wires one in): nothing can
            // synthesize the class, so the degradation is reported here.
            $this->warnings[sprintf(
                'Operation %s: the request body schema is inline (not a $ref to a component schema) and no model generator is wired in to synthesize a Data class; the controller method falls back to Illuminate\Http\Request.',
                $label,
            )] = true;
        }

        // Inline non-object or unresolvable body schema: inject Request.
        $imports[] = self::REQUEST_FQCN;

        return [null, true];
    }

    /**
     * The parameter name a synthesized inline-body Data param takes: `$body`,
     * suffixed deterministically when a path parameter already claimed the
     * name (a `/things/{body}` path would otherwise collide in the signature).
     *
     * @param  list<array{name: string, phpType: string}>  $pathParams
     */
    private function bodyParamName(array $pathParams): string
    {
        $taken = new UniqueNames;
        foreach ($pathParams as $pathParameter) {
            $taken->reserve($pathParameter['name']);
        }

        return $taken->reserve('body');
    }

    /**
     * The selected success response, resolved into the abstract method's
     * return type plus the spec-declared status code (issue #64). A selected
     * 204 returns `void`: RFC 9110 forbids content on a 204, so there is
     * nothing for the implementation to return, and the generated route
     * middleware sets the status and guarantees the empty body.
     *
     * A selected response that was a `$ref` to `#/components/responses/<Name>`
     * (issue #111) arrives here already resolved, with the component name
     * carried alongside: the schema-level `$ref`, array-of-Data, and
     * oneOf/anyOf union paths below apply to the resolved content unchanged,
     * and the one NEW case is an inline JSON object schema, which synthesizes
     * ONE shared `<Component>ResponseData` class for every referencing
     * operation through the model generator's emission pipeline.
     *
     * @param  list<string>  $imports
     * @param  string  $label  "GET /pets", for warning messages
     * @return array{0: string, 1: ?string, 2: ?int} returnType, returnDoc, successStatus
     */
    private function responseType(OperationNode $operation, array &$imports, string $label): array
    {
        [$response, $status, $componentName] = $this->successResponse($operation->responses, $label);

        if ($status === 204) {
            return ['void', null, 204];
        }

        if (! $response instanceof ResponseNode) {
            $imports[] = self::JSON_RESPONSE_FQCN;

            return [self::JSON_RESPONSE_SHORT, null, $status];
        }

        $schema = $this->jsonSchema($response->content);

        if ($schema instanceof ReferenceNode) {
            $pointer = $schema->pointer();
            $name = SchemaPointer::refName($pointer);
            if ($name !== null && isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'data') {
                $type = $this->registry[$name]['dataClass'];
                $imports[] = $this->dataFqcn($type);

                return [$type, null, $status];
            }

            // A local $ref to a non-Data component (an enum, alias, or map) is
            // the documented graceful JsonResponse fallback: only an object
            // shape can type the return. An external or non-schema pointer is
            // information loss, so it warns with the pointer (issue #67).
            if ($name === null) {
                $this->warnings[sprintf(
                    'Operation %s: the response schema $ref "%s" is external or not a #/components/schemas pointer; the return type falls back to JsonResponse.',
                    $label,
                    $pointer,
                )] = true;
            }
        }

        if ($schema instanceof SchemaNode) {
            $collectionOf = $this->arrayItemDataClass($schema);
            if ($collectionOf !== null) {
                $imports[] = self::DATA_COLLECTION_FQCN;
                $imports[] = $this->dataFqcn($collectionOf);

                return [self::DATA_COLLECTION_SHORT, 'DataCollection<int, '.$collectionOf.'>', $status];
            }

            // A oneOf/anyOf response whose members are all generated Data classes
            // becomes a Data-class union return type ('CatData|DogData'); anything
            // messier keeps the JsonResponse fallback below. spatie/laravel-data
            // cannot auto-hydrate such an object union without a discriminator, so
            // this is a typing/IDE/PHPStan improvement, documented in limitations.
            $union = $this->responseUnionDataClasses($schema);
            if ($union !== null) {
                foreach ($union as $dataClass) {
                    $imports[] = $this->dataFqcn($dataClass);
                }

                return [implode('|', $union), null, $status];
            }

            // A component response with an INLINE object schema (issue #111)
            // synthesizes ONE shared class named after the component, placed
            // in the single tag group its referencing operations share (or
            // the flat root when they span groups). A non-object shape
            // returns null with a warning and keeps the JsonResponse
            // fallback below. Inline (non-component) response schemas keep
            // the documented silent fallback: nothing names a shared class.
            if ($componentName !== null && $this->models !== null) {
                $class = $this->models->generateComponentResponseData($componentName, $label, $schema, $this->componentResponseTags[$componentName] ?? null);

                if ($class !== null) {
                    $imports[] = $this->dataFqcn($class);

                    return [$class, null, $status];
                }
            }
        }

        $imports[] = self::JSON_RESPONSE_FQCN;

        return [self::JSON_RESPONSE_SHORT, null, $status];
    }

    /**
     * For a `oneOf`/`anyOf` response schema, the list of generated Data classes
     * the union return type should name, in source order and deduplicated, or
     * null when the union is not made up entirely of generated Data-class $refs.
     *
     * The bar is deliberately strict: a return type union only makes sense when
     * every variant is a concrete Data class the controller can return. A scalar
     * member, an inline schema, a nested composition, or a $ref to an enum or an
     * unknown component all drop the operation back to JsonResponse. A bare
     * `null` member is ignored (a response body is not typically nullable, and a
     * Data-class union return cannot encode it without widening to mixed).
     *
     * @return list<string>|null
     */
    private function responseUnionDataClasses(SchemaNode $schema): ?array
    {
        $members = [];
        foreach ([$schema->oneOf, $schema->anyOf] as $set) {
            if (is_array($set)) {
                foreach ($set as $member) {
                    $members[] = $member;
                }
            }
        }

        if ($members === []) {
            return null;
        }

        $classes = [];
        foreach ($members as $member) {
            // Ignore a bare `{type: null}` member; it carries no Data class.
            if ($member instanceof SchemaNode && $member->type === 'null') {
                continue;
            }

            if (! $member instanceof ReferenceNode) {
                return null;
            }

            $name = SchemaPointer::refName($member->pointer());
            if ($name === null || ! isset($this->registry[$name]) || $this->registry[$name]['kind'] !== 'data') {
                return null;
            }

            $dataClass = $this->registry[$name]['dataClass'];
            if (! in_array($dataClass, $classes, true)) {
                $classes[] = $dataClass;
            }
        }

        return $classes === [] ? null : $classes;
    }

    private function arrayItemDataClass(SchemaNode $schema): ?string
    {
        if (! $this->isArrayType($schema)) {
            return null;
        }

        $items = $schema->items;
        if (! $items instanceof ReferenceNode) {
            return null;
        }

        $name = SchemaPointer::refName($items->pointer());
        if ($name !== null && isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'data') {
            return $this->registry[$name]['dataClass'];
        }

        return null;
    }

    /**
     * The selected success response plus its declared status code. The
     * smallest 2xx status numerically wins; its code is reported even when
     * the response object itself is unusable (an unresolved $ref), because
     * the status is declared either way and the route must honor it (issue
     * #64). The `default`/first-response fallbacks carry no declared success
     * status, so they report null.
     *
     * Only spec-valid status code keys participate (see
     * {@see RESPONSE_KEY_PATTERN}): the cebe object model dropped invalid
     * keys at hydration time, and the selection (in particular the
     * first-response fallback) must keep seeing the same map.
     *
     * A selected response that is a `$ref` resolves against
     * `components.responses` (issue #111) and carries the component name
     * forward, so {@see responseType()} can emit one shared class per
     * component. An UNRESOLVABLE `$ref` (an external pointer, a
     * non-responses pointer, a missing component, or a ref-to-ref chain) is
     * demoted to null exactly as before, but the demotion warns with the
     * pointer (issue #67) instead of silently typing JsonResponse. A `$ref`
     * selected for a 204 stays silent and unresolved: the method is `void`
     * either way, so nothing degrades and nothing needs the body.
     *
     * @param  string  $label  "GET /pets", for warning messages
     * @return array{0: ?ResponseNode, 1: ?int, 2: ?string} response, status, component-response name (when the response came from a resolved `$ref`)
     */
    private function successResponse(?ResponsesNode $responses, string $label): array
    {
        if ($responses === null) {
            return [null, null, null];
        }

        $all = [];
        foreach ($responses->responses as $status => $response) {
            if (preg_match(self::RESPONSE_KEY_PATTERN, (string) $status) === 1) {
                $all[(string) $status] = $response;
            }
        }

        if ($all === []) {
            return [null, null, null];
        }

        // Smallest 2xx status numerically wins.
        $best = null;
        $bestCode = null;
        foreach ($all as $status => $response) {
            $status = (string) $status;
            // The key filter above guarantees a non-empty key, so all-digits
            // is the only check left for the concrete-status form.
            if (strspn($status, '0123456789') === strlen($status)) {
                $code = (int) $status;
                if ($code >= 200 && $code < 300 && ($bestCode === null || $code < $bestCode)) {
                    $bestCode = $code;
                    $best = $response;
                }
            }
        }

        if ($bestCode !== null) {
            // The 204 short-circuit stays ahead of any resolution attempt:
            // the method is `void`, so the response body (resolved or not)
            // is never consulted and never warned about.
            if ($best instanceof ReferenceNode && $bestCode !== 204) {
                [$best, $componentName] = $this->resolveComponentResponse($best, (string) $bestCode, $label);

                if ($best !== null) {
                    return [$best, $bestCode, $componentName];
                }
            }

            return [$best instanceof ResponseNode ? $best : null, $bestCode, null];
        }

        $default = $all['default'] ?? null;
        if ($default instanceof ResponseNode) {
            return [$default, null, null];
        }
        if ($default instanceof ReferenceNode) {
            [$resolved, $componentName] = $this->resolveComponentResponse($default, 'default', $label);

            if ($resolved !== null) {
                return [$resolved, null, $componentName];
            }
        }

        $first = reset($all);
        if ($first instanceof ReferenceNode && $first !== $default) {
            [$resolved, $componentName] = $this->resolveComponentResponse($first, (string) array_key_first($all), $label);

            if ($resolved !== null) {
                return [$resolved, null, $componentName];
            }
        }

        return [$first instanceof ResponseNode ? $first : null, null, null];
    }

    /**
     * Resolve a selected `$ref` response against `components.responses`
     * (issue #111), returning the resolved node plus the component name. An
     * unresolvable `$ref` (external pointer, non-responses pointer, missing
     * component, or ref-to-ref chain) returns [null, null] with one warning
     * naming the pointer (issue #67): the operation cannot derive a typed
     * return from it and falls back to JsonResponse.
     *
     * @param  string  $label  "GET /pets", for warning messages
     * @return array{0: ?ResponseNode, 1: ?string}
     */
    private function resolveComponentResponse(ReferenceNode $response, string $status, string $label): array
    {
        $pointer = $response->pointer();
        $componentName = SchemaPointer::componentName($pointer, 'responses');
        $resolved = $componentName !== null
            ? ($this->componentResponses[$componentName] ?? null)
            : null;

        if ($resolved === null) {
            $this->warnings[sprintf(
                'Operation %s: the "%s" response $ref ("%s") does not resolve to a component response; the return type falls back to JsonResponse.',
                $label,
                $status,
                $pointer,
            )] = true;

            return [null, null];
        }

        return [$resolved, $componentName];
    }

    /**
     * Find the application/json schema in a content map.
     *
     * @param  array<array-key, MediaTypeNode>  $content
     */
    private function jsonSchema(array $content): SchemaNode|ReferenceNode|null
    {
        foreach ($content as $mediaType => $media) {
            if (! is_string($mediaType) || ! $this->isJsonMediaType($mediaType)) {
                continue;
            }

            $schema = $media->schema;
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        $base = strtolower(trim(explode(';', $mediaType)[0]));

        return $base === 'application/json' || str_ends_with($base, '+json');
    }

    /**
     * Find the multipart/form-data schema in a content map (issue #75). Only
     * consulted after {@see jsonSchema()} found nothing, so JSON wins when an
     * operation declares both media types.
     *
     * @param  array<array-key, MediaTypeNode>  $content
     */
    private function multipartSchema(array $content): SchemaNode|ReferenceNode|null
    {
        foreach ($content as $mediaType => $media) {
            if (! is_string($mediaType) || ! $this->isMultipartFormData($mediaType)) {
                continue;
            }

            $schema = $media->schema;
            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }

    private function isMultipartFormData(string $mediaType): bool
    {
        return strtolower(trim(explode(';', $mediaType)[0])) === 'multipart/form-data';
    }

    private function scalarType(SchemaNode $schema): ?string
    {
        $raw = $schema->type;
        $types = [];

        if (is_array($raw)) {
            $types = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $types = [$raw];
        }

        foreach ($types as $type) {
            if (is_string($type) && $type !== 'null' && $type !== '') {
                return $type;
            }
        }

        return null;
    }

    private function isArrayType(SchemaNode $schema): bool
    {
        return $this->scalarType($schema) === 'array';
    }

    private function dataFqcn(string $shortName): string
    {
        // Under the tag-grouped data layout (issue #93) a Data class may live
        // in a tag subnamespace; the generator knows where each class landed.
        // Without a wired generator (legacy call sites and tests) every class
        // is at the flat root, the historical behavior.
        if ($this->models !== null) {
            return $this->models->namespaceFor($shortName).'\\'.$shortName;
        }

        return $this->options->dataNamespace.'\\'.$shortName;
    }

    private function summary(OperationNode $operation): ?string
    {
        $summary = $operation->summary;

        return $summary !== null && trim($summary) !== '' ? trim($summary) : null;
    }

    /**
     * Extract the parameter name from a `{name}` path segment, or null when the
     * segment is a literal. Tolerant of partial braces and empty tokens.
     */
    private function pathToken(string $segment): ?string
    {
        if (! str_starts_with($segment, '{') || ! str_ends_with($segment, '}')) {
            return null;
        }

        $inner = substr($segment, 1, -1);

        return $inner === '' ? null : $inner;
    }

    private function methodRank(string $method): int
    {
        $rank = array_search($method, self::HTTP_METHODS, true);

        return $rank === false ? count(self::HTTP_METHODS) : $rank;
    }
}
