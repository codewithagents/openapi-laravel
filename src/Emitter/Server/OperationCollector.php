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
 * injecting Request / returning JsonResponse.
 *
 * Output is deterministic: descriptors are sorted by path, then by a fixed
 * HTTP-method order, and method names are made unique per controller.
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
    ) {}

    /**
     * @return list<OperationDescriptor>
     */
    public function collect(OpenApi $document): array
    {
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

                $rows[] = ['path' => $path, 'method' => $method, 'operation' => $operation];
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

        $descriptors = [];
        foreach ($rows as $row) {
            $descriptors[] = $this->describe($row['path'], $row['method'], $row['operation'], $methodNames);
        }

        return $descriptors;
    }

    /**
     * @param  array<string, UniqueNames>  $methodNames
     */
    private function describe(string $path, string $method, Operation $operation, array &$methodNames): OperationDescriptor
    {
        $tag = $this->firstTag($operation);
        $controllerClass = PhpIdentifier::toClassName($tag).$this->options->controllerSuffix;
        $abstractClass = $this->options->abstractPrefix.$controllerClass;

        if (! isset($methodNames[$controllerClass])) {
            $methodNames[$controllerClass] = new UniqueNames;
        }
        $methodName = $methodNames[$controllerClass]->reserve($this->methodName($operation, $method, $path));

        $pathParams = $this->pathParams($path, $operation);
        $imports = [];

        [$bodyParam, $bodyRequiresRequest] = $this->requestBody($operation, $imports);
        [$returnType, $returnDoc] = $this->responseType($operation, $imports);

        sort($imports);
        $imports = array_values(array_unique($imports));

        return new OperationDescriptor(
            httpMethod: $method,
            path: $path,
            controllerClass: $controllerClass,
            abstractClass: $abstractClass,
            methodName: $methodName,
            pathParams: $pathParams,
            bodyParam: $bodyParam,
            bodyRequiresRequest: $bodyRequiresRequest,
            returnType: $returnType,
            returnDoc: $returnDoc,
            summary: $this->summary($operation),
            imports: $imports,
        );
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
     * @return list<array{name: string, phpType: string}>
     */
    private function pathParams(string $path, Operation $operation): array
    {
        $types = $this->pathParamTypes($operation);
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
     * @return array<string, string>
     */
    private function pathParamTypes(Operation $operation): array
    {
        $types = [];

        foreach ($this->asArray($operation->parameters) as $parameter) {
            if (! $parameter instanceof Parameter || $parameter->in !== 'path') {
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
     * @param  list<string>  $imports
     * @return array{0: array{name: string, type: string}|null, 1: bool}
     */
    private function requestBody(Operation $operation, array &$imports): array
    {
        $body = $operation->requestBody;

        if (! $body instanceof RequestBody) {
            // No body, or an unresolved $ref body we cannot inspect: a Reference
            // here means a component requestBody, which we treat as untyped and
            // therefore inject Request. The import must be pushed too, exactly
            // like the other requiresRequest paths below, or the generated
            // controller references Request without a matching use statement.
            if ($body instanceof Reference) {
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
                $imports[] = self::REQUEST_FQCN;

                return [null, true];
            }

            return [null, false];
        }

        if ($schema instanceof Reference) {
            $name = $this->refName($schema->getReference());
            if ($name !== null && isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'data') {
                $entry = $this->registry[$name];
                $type = $entry['writeClass'] ?? $entry['dataClass'];
                $imports[] = $this->dataFqcn($type);

                return [['name' => PhpIdentifier::toPropertyName($name), 'type' => $type], false];
            }
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
     * @param  list<string>  $imports
     * @return array{0: string, 1: ?string} returnType, returnDoc
     */
    private function responseType(Operation $operation, array &$imports): array
    {
        $response = $this->successResponse($operation->responses);

        if (! $response instanceof Response) {
            $imports[] = self::JSON_RESPONSE_FQCN;

            return [self::JSON_RESPONSE_SHORT, null];
        }

        $schema = $this->jsonSchema($this->asArray($response->content));

        if ($schema instanceof Reference) {
            $name = $this->refName($schema->getReference());
            if ($name !== null && isset($this->registry[$name]) && $this->registry[$name]['kind'] === 'data') {
                $type = $this->registry[$name]['dataClass'];
                $imports[] = $this->dataFqcn($type);

                return [$type, null];
            }
        }

        if ($schema instanceof Schema) {
            $collectionOf = $this->arrayItemDataClass($schema);
            if ($collectionOf !== null) {
                $imports[] = self::DATA_COLLECTION_FQCN;
                $imports[] = $this->dataFqcn($collectionOf);

                return [self::DATA_COLLECTION_SHORT, 'DataCollection<int, '.$collectionOf.'>'];
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

                return [implode('|', $union), null];
            }
        }

        $imports[] = self::JSON_RESPONSE_FQCN;

        return [self::JSON_RESPONSE_SHORT, null];
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

    private function successResponse(mixed $responses): ?Response
    {
        if (! $responses instanceof Responses) {
            return null;
        }

        $all = [];
        foreach ($responses->getResponses() as $status => $response) {
            $all[(string) $status] = $response;
        }

        if ($all === []) {
            return null;
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

        if ($best !== null) {
            return $best instanceof Response ? $best : null;
        }

        $default = $all['default'] ?? null;
        if ($default instanceof Response) {
            return $default;
        }

        $first = reset($all);

        return $first instanceof Response ? $first : null;
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
        if (! str_starts_with($pointer, '#/components/schemas/')) {
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
