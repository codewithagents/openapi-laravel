<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody;
use cebe\openapi\spec\Response;
use cebe\openapi\spec\Responses;
use cebe\openapi\spec\Schema;
use cebe\openapi\spec\SecurityRequirement;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\ResolvedClosure;
use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;

/**
 * Walks an OpenAPI document and resolves each operation into an
 * {@see OperationDescriptor}, using the model generator's registry to type
 * request bodies and responses against the already-generated Data classes.
 *
 * Robustness is a hard requirement: a missing operationId, absent tags, no
 * responses, inline/non-JSON bodies, weird path tokens, and unresolved $refs
 * must never fatal. When a type cannot be derived the collector falls back to
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
         * Query-parameter support (issue #63). When non-null, each operation's
         * `in: query` parameters are turned into a per-operation query Data
         * class through the model generator's rules pipeline (the generator
         * must already have run generate() for this document). Null (the
         * default) skips query emission, keeping legacy call sites and tests
         * byte-identical.
         */
        private readonly ?ModelGenerator $models = null,
    ) {}

    /**
     * Non-fatal diagnostics from the last collect() run, sorted for
     * determinism: header/cookie parameters the scaffold does not generate
     * yet, plus every silent degradation the collection had to take (issue
     * #67): a body or response that fell back to Request/JsonResponse over an
     * unresolved or unsupported $ref, an inline or non-JSON body, and a
     * dropped parameter $ref. Keyed by message so a path-level parameter
     * shared across the operations of one path is reported once per
     * operation, never duplicated across re-collections. Mirrors
     * ModelGenerator::warnings().
     *
     * @var array<string, true>
     */
    private array $warnings = [];

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
    public function collect(OpenApi $document): array
    {
        $this->warnings = [];
        $componentParameters = $this->componentParameters($document);

        // Security-to-middleware resolution (issue #77): one resolver per run,
        // seeded with the configured map and the document-level security so
        // every operation resolves against the same state. The typo check
        // (a mapped scheme the spec never declares) runs once, up front.
        $security = new SecurityMiddlewareResolver(
            $this->options->securityMiddlewareMap,
            $this->securityRequirements($document->security) ?? [],
        );
        $security->warnUndeclaredMappings($this->declaredSchemeNames($document));

        $rows = [];

        foreach ($this->pathItems($document) as $path => $pathItem) {
            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem->{$method} ?? null;

                if (! $operation instanceof Operation) {
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

        // Opt-in Laravel-convention method names (issue #94): resolve each
        // operation's conventional candidate BEFORE the descriptors are built,
        // so the per-controller ambiguity rule (a conventional name claimed by
        // more than one operation in the same controller makes ALL claimants
        // fall back) is decided over the whole controller at once,
        // independently of descriptor order.
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
     * should try first, aligned with $rows by index. All null when the option
     * is off (the default), keeping the output byte-identical to before.
     *
     * Two passes so the result is order-independent: first every row's raw
     * candidate is counted per (controller, name) pair, then a candidate
     * claimed by more than one operation in the SAME controller is withdrawn
     * for all claimants (e.g. two collection GETs under one tag would both be
     * `index`, so both keep their operationId-derived name instead). The same
     * conventional name in different controllers is fine.
     *
     * @param  list<array{path: string, method: string, operation: Operation, parameters: list<Parameter>}>  $rows
     * @return array<int, ?string> aligned with $rows by index
     */
    private function conventionalNames(array $rows): array
    {
        if (! $this->options->laravelConventions) {
            return array_fill(0, count($rows), null);
        }

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
    private function controllerClass(Operation $operation): string
    {
        return PhpIdentifier::toClassName($this->firstTag($operation)).$this->options->controllerSuffix;
    }

    /**
     * @param  list<Parameter>  $parameters
     * @param  array<string, UniqueNames>  $methodNames
     */
    private function describe(string $path, string $method, Operation $operation, array $parameters, array &$methodNames, UniqueNames $routeNames, SecurityMiddlewareResolver $security, ?string $conventionalName): OperationDescriptor
    {
        $controllerClass = $this->controllerClass($operation);
        $abstractClass = $this->options->abstractPrefix.$controllerClass;

        if (! isset($methodNames[$controllerClass])) {
            $methodNames[$controllerClass] = new UniqueNames;
        }

        // The unambiguous conventional name (issue #94, opt-in) wins over the
        // operationId-derived one; any residual clash inside the controller
        // (say, another operation whose operationId is literally `index`)
        // still goes through the per-controller allocator and gets suffixed.
        $methodName = $methodNames[$controllerClass]->reserve($conventionalName ?? $this->methodName($operation, $method, $path));

        // The route name reuses the (already per-controller-unique) method
        // name as its candidate, then the global allocator suffixes any
        // cross-controller clash (_2, _3, ...). Deterministic because the
        // descriptor order is. Under --laravel-conventions this deliberately
        // follows the CHOSEN method name (issue #94), so the routes file reads
        // conventionally too; with several controllers each owning an `index`,
        // the later ones become index_2, index_3, ...
        $routeName = $routeNames->reserve($methodName);

        $pathParams = $this->pathParams($path, $parameters);
        $imports = [];

        // "GET /pets", the operation label every degradation warning leads with.
        $label = strtoupper($method).' '.$path;

        [$bodyParam, $bodyRequiresRequest] = $this->requestBody($operation, $imports, $label);
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
            securityMiddleware: $security->middlewareFor($label, $this->securityRequirements($operation->security)),
        );
    }

    /**
     * Normalize a spec-level `security` value into a clean requirement list,
     * preserving the three-way distinction the resolver needs: null (not
     * declared), [] (explicitly public), or the requirement objects. Anything
     * that is not a SecurityRequirement (a malformed entry in a hostile spec)
     * is dropped.
     *
     * @return list<SecurityRequirement>|null
     */
    private function securityRequirements(mixed $security): ?array
    {
        if (! is_array($security)) {
            return null;
        }

        $requirements = [];
        foreach ($security as $requirement) {
            if ($requirement instanceof SecurityRequirement) {
                $requirements[] = $requirement;
            }
        }

        return $requirements;
    }

    /**
     * The scheme names `components.securitySchemes` declares. Only the names
     * matter for the middleware map; the scheme objects themselves (or
     * unresolved $refs to them) are never inspected.
     *
     * @return list<string>
     */
    private function declaredSchemeNames(OpenApi $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $names = [];
        foreach (array_keys($this->asArray($components->securitySchemes)) as $name) {
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
     * @param  list<Parameter>  $parameters
     * @param  array{name: string, type: string}|null  $bodyParam
     * @param  list<array{name: string, phpType: string}>  $pathParams
     * @param  list<string>  $imports
     * @return array{name: string, type: string, injected: bool}|null
     */
    private function queryParam(string $method, string $path, Operation $operation, array $parameters, ?array $bodyParam, bool $bodyRequiresRequest, array $pathParams, array &$imports): ?array
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

        // The class name derives from the same operationId-or-fallback the
        // method name uses, so `findPetsByStatus` yields FindPetsByStatusQueryData.
        // Deliberately NOT the conventional name under --laravel-conventions
        // (issue #94): query classes live in the global Data namespace, where
        // an `IndexQueryData` would clash across controllers, so the Data
        // layer stays operationId-derived (and byte-identical) in both modes.
        $baseName = PhpIdentifier::toClassName($this->methodName($operation, $method, $path));
        $label = strtoupper($method).' '.$path;

        $class = $this->models->generateQueryData($baseName, $label, $queryParameters);
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

        return ['name' => $taken->reserve('query'), 'type' => $class, 'injected' => $injected];
    }

    /**
     * Record a warning for each `in: header` / `in: cookie` parameter group on
     * an operation: the scaffold does not generate typing or validation for
     * those locations yet (issue #63 scoped them out), and silence would hide
     * the information loss. One warning per location kind per operation, with
     * the parameter names listed, so a spec-wide trace header does not flood
     * the channel.
     *
     * @param  list<Parameter>  $parameters
     */
    private function warnUnsupportedParameterLocations(string $method, string $path, array $parameters): void
    {
        foreach (['header', 'cookie'] as $kind) {
            $names = [];
            foreach ($parameters as $parameter) {
                if ($parameter->in === $kind && is_string($parameter->name) && $parameter->name !== '') {
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

    private function firstTag(Operation $operation): string
    {
        $tags = $operation->tags;

        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    return $tag;
                }
            }
        }

        // 'Untagged' is not a reserved word, so it survives toClassName intact
        // (unlike 'Default', which would escape to _Default).
        return 'Untagged';
    }

    private function methodName(Operation $operation, string $method, string $path): string
    {
        $operationId = $operation->operationId;

        if (is_string($operationId) && trim($operationId) !== '') {
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
     * @param  list<Parameter>  $parameters
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
     * @param  list<Parameter>  $parameters
     * @return array<string, string>
     */
    private function pathParamTypes(array $parameters): array
    {
        $types = [];

        foreach ($parameters as $parameter) {
            if ($parameter->in !== 'path') {
                continue;
            }

            $name = $parameter->name;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $schema = $parameter->schema;
            if ($schema instanceof Schema) {
                $type = $this->scalarType($schema);
                if ($type !== null) {
                    $types[$name] = $type;
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
     * with a warning naming the pointer (issue #67); a malformed (non-object)
     * entry is still skipped silently, there is nothing to name.
     *
     * This is the single collection point for parameters of every `in` kind,
     * so the upcoming query-parameter support (#63) can reuse it as-is.
     *
     * @param  array<string, Parameter>  $componentParameters
     * @param  string  $label  "GET /pets", for warning messages
     * @return list<Parameter>
     */
    private function mergedParameters(PathItem $pathItem, Operation $operation, array $componentParameters, string $label): array
    {
        $byKey = [];

        // Path level first, operation level second: a later write to the same
        // (in, name) key replaces the earlier one, which is exactly the
        // override the OpenAPI spec mandates.
        foreach ([$this->asArray($pathItem->parameters), $this->asArray($operation->parameters)] as $level) {
            foreach ($level as $candidate) {
                $parameter = $this->resolveParameter($candidate, $componentParameters, $label);

                if ($parameter === null) {
                    continue;
                }

                $name = $parameter->name;
                $in = $parameter->in;
                if (! is_string($name) || $name === '' || ! is_string($in) || $in === '') {
                    continue;
                }

                $byKey[$in.' '.$name] = $parameter;
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
     * @param  array<string, Parameter>  $componentParameters
     * @param  string  $label  "GET /pets", for warning messages
     */
    private function resolveParameter(mixed $candidate, array $componentParameters, string $label): ?Parameter
    {
        if ($candidate instanceof Parameter) {
            return $candidate;
        }

        if ($candidate instanceof Reference) {
            $pointer = $candidate->getReference();
            $name = $this->componentName($pointer, 'parameters');

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

        return null;
    }

    /**
     * The document's `components.parameters` map, keyed by component name.
     * Only direct Parameter entries are kept: a ref-to-ref chain inside the
     * components is left unresolved and degrades like any other unresolvable
     * ref.
     *
     * @return array<string, Parameter>
     */
    private function componentParameters(OpenApi $document): array
    {
        $components = $document->components;

        if ($components === null) {
            return [];
        }

        $result = [];
        foreach ($this->asArray($components->parameters) as $name => $parameter) {
            if (is_string($name) && $name !== '' && $parameter instanceof Parameter) {
                $result[$name] = $parameter;
            }
        }

        return $result;
    }

    /**
     * Resolve the request body into a typed Data param, or fall back to
     * injecting `Illuminate\Http\Request`. Every fallback emits a warning
     * naming the operation and the cause (issue #67): the fallback compiles
     * and runs, but it silently drops the spec-declared body validation,
     * which a user should learn at generation time.
     *
     * @param  list<string>  $imports
     * @param  string  $label  "GET /pets", for warning messages
     * @return array{0: array{name: string, type: string}|null, 1: bool}
     */
    private function requestBody(Operation $operation, array &$imports, string $label): array
    {
        $body = $operation->requestBody;

        if (! $body instanceof RequestBody) {
            // No body, or an unresolved $ref body we cannot inspect: a Reference
            // here means a component requestBody, which we treat as untyped and
            // therefore inject Request. The import must be pushed too, exactly
            // like the other requiresRequest paths below, or the generated
            // controller references Request without a matching use statement.
            if ($body instanceof Reference) {
                $this->warnings[sprintf(
                    'Operation %s: the request body is a $ref ("%s") and component request bodies are not resolved yet; the controller method falls back to Illuminate\Http\Request.',
                    $label,
                    $body->getReference(),
                )] = true;
                $imports[] = self::REQUEST_FQCN;

                return [null, true];
            }

            return [null, false];
        }

        $schema = $this->jsonSchema($this->asArray($body->content));

        if ($schema === null) {
            // Body exists but no application/json schema (inline, octet-stream,
            // form-urlencoded, etc.): fall back to injecting Request.
            if ($this->bodyContentPresent($body)) {
                $this->warnings[sprintf(
                    'Operation %s: the request body declares no application/json schema; no body validation is generated and the controller method falls back to Illuminate\Http\Request.',
                    $label,
                )] = true;
                $imports[] = self::REQUEST_FQCN;

                return [null, true];
            }

            return [null, false];
        }

        if ($schema instanceof Reference) {
            $pointer = $schema->getReference();
            $name = $this->refName($pointer);
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
        } else {
            $this->warnings[sprintf(
                'Operation %s: the request body schema is inline (not a $ref to a component schema) and inline bodies are not generated yet; the controller method falls back to Illuminate\Http\Request.',
                $label,
            )] = true;
        }

        // Inline or unresolvable body schema: inject Request.
        $imports[] = self::REQUEST_FQCN;

        return [null, true];
    }

    private function bodyContentPresent(RequestBody $body): bool
    {
        return $this->asArray($body->content) !== [];
    }

    /**
     * The selected success response, resolved into the abstract method's
     * return type plus the spec-declared status code (issue #64). A selected
     * 204 returns `void`: RFC 9110 forbids content on a 204, so there is
     * nothing for the implementation to return, and the generated route
     * middleware sets the status and guarantees the empty body.
     *
     * @param  list<string>  $imports
     * @param  string  $label  "GET /pets", for warning messages
     * @return array{0: string, 1: ?string, 2: ?int} returnType, returnDoc, successStatus
     */
    private function responseType(Operation $operation, array &$imports, string $label): array
    {
        [$response, $status] = $this->successResponse($operation->responses, $label);

        if ($status === 204) {
            return ['void', null, 204];
        }

        if (! $response instanceof Response) {
            $imports[] = self::JSON_RESPONSE_FQCN;

            return [self::JSON_RESPONSE_SHORT, null, $status];
        }

        $schema = $this->jsonSchema($this->asArray($response->content));

        if ($schema instanceof Reference) {
            $pointer = $schema->getReference();
            $name = $this->refName($pointer);
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

        if ($schema instanceof Schema) {
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
    private function responseUnionDataClasses(Schema $schema): ?array
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
            if ($member instanceof Schema && $member->type === 'null') {
                continue;
            }

            if (! $member instanceof Reference) {
                return null;
            }

            $name = $this->refName($member->getReference());
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

    private function arrayItemDataClass(Schema $schema): ?string
    {
        if (! $this->isArrayType($schema)) {
            return null;
        }

        $items = $schema->items;
        if (! $items instanceof Reference) {
            return null;
        }

        $name = $this->refName($items->getReference());
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
     * A selected response that is an unresolved `$ref` (a pointer into
     * `components.responses`, which this generator does not resolve) is
     * demoted to null exactly as before, but the demotion now warns with the
     * pointer (issue #67) instead of silently typing JsonResponse. A `$ref`
     * selected for a 204 stays silent: the method is `void` either way, so
     * nothing degrades.
     *
     * @param  string  $label  "GET /pets", for warning messages
     * @return array{0: ?Response, 1: ?int}
     */
    private function successResponse(mixed $responses, string $label): array
    {
        if (! $responses instanceof Responses) {
            return [null, null];
        }

        $all = [];
        foreach ($responses->getResponses() as $status => $response) {
            $all[(string) $status] = $response;
        }

        if ($all === []) {
            return [null, null];
        }

        // Smallest 2xx status numerically wins.
        $best = null;
        $bestCode = null;
        foreach ($all as $status => $response) {
            $status = (string) $status;
            if (ctype_digit($status)) {
                $code = (int) $status;
                if ($code >= 200 && $code < 300 && ($bestCode === null || $code < $bestCode)) {
                    $bestCode = $code;
                    $best = $response;
                }
            }
        }

        if ($bestCode !== null) {
            if ($best instanceof Reference && $bestCode !== 204) {
                $this->warnRefResponse($label, (string) $bestCode, $best);
            }

            return [$best instanceof Response ? $best : null, $bestCode];
        }

        $default = $all['default'] ?? null;
        if ($default instanceof Response) {
            return [$default, null];
        }
        if ($default instanceof Reference) {
            $this->warnRefResponse($label, 'default', $default);
        }

        $first = reset($all);
        if ($first instanceof Reference && $first !== $default) {
            $this->warnRefResponse($label, (string) array_key_first($all), $first);
        }

        return [$first instanceof Response ? $first : null, null];
    }

    /**
     * One warning per unresolved `$ref` response the selection had to bypass
     * (issue #67): component responses are not resolved, so the operation
     * cannot derive a typed return from them and falls back to JsonResponse.
     */
    private function warnRefResponse(string $label, string $status, Reference $response): void
    {
        $this->warnings[sprintf(
            'Operation %s: the "%s" response is a $ref ("%s") and component responses are not resolved yet; the return type falls back to JsonResponse.',
            $label,
            $status,
            $response->getReference(),
        )] = true;
    }

    /**
     * Find the application/json schema in a content map.
     *
     * @param  array<array-key, mixed>  $content
     */
    private function jsonSchema(array $content): Schema|Reference|null
    {
        foreach ($content as $mediaType => $media) {
            if (! is_string($mediaType) || ! $this->isJsonMediaType($mediaType) || ! $media instanceof MediaType) {
                continue;
            }

            $schema = $media->schema;
            if ($schema instanceof Schema || $schema instanceof Reference) {
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

    private function scalarType(Schema $schema): ?string
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

    private function isArrayType(Schema $schema): bool
    {
        return $this->scalarType($schema) === 'array';
    }

    private function dataFqcn(string $shortName): string
    {
        return $this->options->dataNamespace.'\\'.$shortName;
    }

    private function summary(Operation $operation): ?string
    {
        $summary = $operation->summary;

        return is_string($summary) && trim($summary) !== '' ? trim($summary) : null;
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

    /**
     * @return array<string, PathItem>
     */
    private function pathItems(OpenApi $document): array
    {
        $paths = $document->paths;

        if ($paths === null) {
            return [];
        }

        $result = [];
        foreach ($paths->getPaths() as $path => $pathItem) {
            // Unresolved $ref path items are skipped: nothing to route from them.
            if ($pathItem instanceof PathItem) {
                $result[(string) $path] = $pathItem;
            }
        }

        return $result;
    }

    private function refName(string $pointer): ?string
    {
        return $this->componentName($pointer, 'schemas');
    }

    /**
     * The component name a local `#/components/<section>/<Name>` pointer
     * targets, or null for an external or differently shaped pointer.
     */
    private function componentName(string $pointer, string $section): ?string
    {
        if (! str_starts_with($pointer, '#/components/'.$section.'/')) {
            return null;
        }

        $parts = explode('/', $pointer);
        $last = end($parts);

        return $last === '' ? null : $last;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
