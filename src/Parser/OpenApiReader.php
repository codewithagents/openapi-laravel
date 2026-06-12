<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\ComponentsNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\DiscriminatorNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\InfoNode;
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
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SecurityRequirementNode;

/**
 * Hydrates the already-decoded raw spec array into the internal read-only
 * value-object graph under {@see Spec} (issue #104). This replaced cebe's
 * object model: typed output instead of a loosely-typed graph, with every
 * keyword the emitter consumes a first-class typed property.
 *
 * What it owns:
 *   - The schema normalization rewrites (issues #20, #32, #33, #82):
 *     `items: true` becomes an empty SchemaNode, `items: false` is dropped
 *     after synthesizing the closed-tuple `maxItems`, strictly-numeric strings
 *     on numeric keywords are coerced to int/float, and a `nullable` of
 *     "true"/"false" (case-insensitive) is coerced to the matching boolean.
 *   - The structural rejection and the exact version gate (issue #103):
 *     missing `openapi`, unsupported versions, and a missing `info` object are
 *     rejected with clear messages; a 3.2 document is accepted best-effort
 *     with a loud warning plus one warning per dropped 3.2-only construct.
 *     Warnings travel ON the document (`OpenApiDocument::$warnings`), and
 *     SpecParser mirrors them into its `warnings()` surface.
 *
 * What it deliberately does NOT do:
 *   - No file IO. The size guard, MemoryGuard arm/disarm, and format sniffing
 *     stay in {@see SpecParser}, which slots this reader underneath via
 *     `parseFileToDocument()`. The reader sees only decoded data.
 *   - No external reference resolution. Every `$ref` becomes a
 *     {@see ReferenceNode} carrying its raw pointer string; the emitter
 *     resolves the references it needs, with cycle protection, exactly as it
 *     does today.
 *
 * Leniency policy: the spec is untrusted input, so hydration never crashes on
 * a mistyped member. A known keyword whose value has the wrong shape lands in
 * the node's raw `extra` bag (where the node has one) or is skipped, matching
 * the emitter's existing behavior of ignoring values that fail its type
 * checks. A boolean JSON Schema in a general schema position hydrates to its
 * exact JSON-Schema equivalent: `true` to the empty schema, `false` to
 * `{"not": {}}`.
 *
 * @internal
 */
final class OpenApiReader
{
    /**
     * Upper bound on schema nesting during hydration, guarding the recursive
     * walk against hostile deeply-nested input. The guard rejects a schema
     * only once its depth EXCEEDS this bound (the root schema sits at depth
     * 0), so up to maxDepth + 1 schema levels hydrate: 513 with the default.
     * That is one level more than `json_decode`'s default depth limit of 512,
     * so a JSON spec that decodes at all also hydrates; override via the
     * constructor only for trusted specs.
     */
    public const DEFAULT_MAX_DEPTH = 512;

    /**
     * The canonical statement of the supported version matrix (issue #103).
     * Both the rejection error and the 3.2 best-effort warning point here so
     * the docs page stays the single source of truth.
     */
    public const VERSION_MATRIX_URL = 'https://openapi-laravel.codewithagents.de/guides/openapi-versions/';

    public const ISSUE_102_URL = 'https://github.com/codewithagents/openapi-laravel/issues/102';

    public const SUPPORTED_MATRIX = 'Supported versions: OpenAPI 3.0.x and 3.1.x (fully), 3.2.x (accepted best-effort with warnings). See '.self::VERSION_MATRIX_URL;

    /**
     * Numeric schema keywords whose strictly-numeric string values are coerced
     * to int/float (issue #32). Kept here as the
     * authoritative list for the integer-valued keywords; the int|float ones
     * (minimum, maximum, multipleOf, the exclusive bounds) coerce through
     * their own typed cases.
     *
     * @var list<string>
     */
    private const INT_KEYS = [
        'minLength',
        'maxLength',
        'minItems',
        'maxItems',
        'minProperties',
        'maxProperties',
    ];

    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace', 'query'];

    public function __construct(
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ) {}

    /**
     * Hydrate a decoded spec (the output of `Yaml::parse` or `json_decode`)
     * into the typed document graph. `$source` is a human-readable label
     * (usually the file path) used in error and warning messages.
     *
     * @throws ParseException when the document is structurally not an OpenAPI 3.x spec, carries an unsupported version, or nests beyond the depth bound
     */
    public function read(mixed $data, string $source = 'spec'): OpenApiDocument
    {
        if (! is_array($data)) {
            $data = [];
        }

        $version = $data['openapi'] ?? null;

        if (! is_string($version) || $version === '') {
            throw new ParseException("Not an OpenAPI 3.x document ({$source}): missing 'openapi' version string. Swagger 2.0 and other formats are not supported. ".self::SUPPORTED_MATRIX);
        }

        if (preg_match('/^3\.(\d+)(?:[.\-+]|$)/', $version, $matches) !== 1 || ! in_array((int) $matches[1], [0, 1, 2], true)) {
            throw new ParseException("Unsupported OpenAPI version '{$version}' ({$source}). ".self::SUPPORTED_MATRIX);
        }

        $rawInfo = $data['info'] ?? null;

        if (! is_array($rawInfo)) {
            throw new ParseException("Not a valid OpenAPI document ({$source}): missing required 'info' object.");
        }

        $warnings = [];

        if ((int) $matches[1] === 2) {
            $warnings[] = sprintf(
                "OpenAPI 3.2 is not fully supported yet: '%s' (%s) is accepted best-effort, and 3.2-only constructs are dropped from the generated output. Full 3.2 support is tracked in issue #102 (%s). %s",
                $version,
                $source,
                self::ISSUE_102_URL,
                self::SUPPORTED_MATRIX,
            );
            $warnings = [...$warnings, ...$this->scanDropped32Constructs($data)];
        }

        $paths = [];
        $rawPaths = $data['paths'] ?? null;
        if (is_array($rawPaths)) {
            foreach ($rawPaths as $route => $item) {
                if (is_array($item)) {
                    $paths[(string) $route] = $this->pathItem($item);
                }
            }
        }

        $webhooks = [];
        $rawWebhooks = $data['webhooks'] ?? null;
        if (is_array($rawWebhooks)) {
            foreach ($rawWebhooks as $name => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $webhooks[(string) $name] = $this->isReference($item)
                    ? $this->reference($item)
                    : $this->pathItem($item);
            }
        }

        $rawComponents = $data['components'] ?? null;

        return new OpenApiDocument(
            openapi: $version,
            info: $this->info($rawInfo),
            paths: $paths,
            components: is_array($rawComponents) ? $this->components($rawComponents) : null,
            webhooks: $webhooks,
            security: array_key_exists('security', $data) ? $this->securityList($data['security']) : null,
            tags: array_key_exists('tags', $data) ? $this->rawObjectList($data['tags']) : null,
            servers: array_key_exists('servers', $data) ? $this->rawObjectList($data['servers']) : null,
            warnings: $warnings,
            extensions: $this->extensions($data),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function info(array $raw): InfoNode
    {
        $title = $raw['title'] ?? null;
        $version = $raw['version'] ?? null;

        return new InfoNode(
            title: is_string($title) ? $title : '',
            version: is_string($version) ? $version : '',
            summary: $this->stringOrNull($raw['summary'] ?? null),
            description: $this->stringOrNull($raw['description'] ?? null),
            termsOfService: $this->stringOrNull($raw['termsOfService'] ?? null),
            contact: array_key_exists('contact', $raw) ? $this->rawObjectMap($raw['contact']) : null,
            license: array_key_exists('license', $raw) ? $this->rawObjectMap($raw['license']) : null,
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function pathItem(array $raw): PathItemNode
    {
        $methods = [];
        foreach (self::HTTP_METHODS as $method) {
            $value = $raw[$method] ?? null;
            $methods[$method] = is_array($value) ? $this->operation($value) : null;
        }

        $additionalOperations = null;
        $rawAdditional = $raw['additionalOperations'] ?? null;
        if (is_array($rawAdditional)) {
            $additionalOperations = [];
            foreach ($rawAdditional as $method => $value) {
                if (is_array($value)) {
                    $additionalOperations[(string) $method] = $this->operation($value);
                }
            }
        }

        $ref = $raw['$ref'] ?? null;

        return new PathItemNode(
            summary: $this->stringOrNull($raw['summary'] ?? null),
            description: $this->stringOrNull($raw['description'] ?? null),
            get: $methods['get'],
            put: $methods['put'],
            post: $methods['post'],
            delete: $methods['delete'],
            options: $methods['options'],
            head: $methods['head'],
            patch: $methods['patch'],
            trace: $methods['trace'],
            query: $methods['query'],
            additionalOperations: $additionalOperations,
            parameters: $this->parameterList($raw['parameters'] ?? null),
            ref: is_string($ref) ? $ref : null,
            servers: array_key_exists('servers', $raw) ? $this->rawObjectList($raw['servers']) : null,
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function operation(array $raw): OperationNode
    {
        $tags = [];
        $rawTags = $raw['tags'] ?? null;
        if (is_array($rawTags)) {
            $tags = array_values(array_filter($rawTags, is_string(...)));
        }

        $requestBody = null;
        $rawBody = $raw['requestBody'] ?? null;
        if (is_array($rawBody)) {
            $requestBody = $this->isReference($rawBody)
                ? $this->reference($rawBody)
                : $this->requestBody($rawBody);
        }

        $rawResponses = $raw['responses'] ?? null;

        return new OperationNode(
            operationId: $this->stringOrNull($raw['operationId'] ?? null),
            tags: $tags,
            summary: $this->stringOrNull($raw['summary'] ?? null),
            description: $this->stringOrNull($raw['description'] ?? null),
            parameters: $this->parameterList($raw['parameters'] ?? null),
            requestBody: $requestBody,
            responses: is_array($rawResponses) ? $this->responses($rawResponses) : null,
            deprecated: $this->boolOrNull($raw['deprecated'] ?? null),
            security: array_key_exists('security', $raw) ? $this->securityList($raw['security']) : null,
            callbacks: array_key_exists('callbacks', $raw) ? $this->rawObjectMap($raw['callbacks']) : null,
            servers: array_key_exists('servers', $raw) ? $this->rawObjectList($raw['servers']) : null,
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @return list<ParameterNode|ReferenceNode>
     */
    private function parameterList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $parameters = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $parameters[] = $this->isReference($entry)
                ? $this->reference($entry)
                : $this->parameter($entry);
        }

        return $parameters;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function parameter(array $raw): ParameterNode
    {
        $name = $raw['name'] ?? null;
        $in = $raw['in'] ?? null;

        return new ParameterNode(
            name: is_string($name) ? $name : '',
            in: is_string($in) ? $in : '',
            description: $this->stringOrNull($raw['description'] ?? null),
            required: $this->boolOrNull($raw['required'] ?? null),
            deprecated: $this->boolOrNull($raw['deprecated'] ?? null),
            allowEmptyValue: $this->boolOrNull($raw['allowEmptyValue'] ?? null),
            style: $this->stringOrNull($raw['style'] ?? null),
            explode: $this->boolOrNull($raw['explode'] ?? null),
            allowReserved: $this->boolOrNull($raw['allowReserved'] ?? null),
            schema: $this->subschema($raw['schema'] ?? null, 0),
            example: $raw['example'] ?? null,
            examples: array_key_exists('examples', $raw) ? $this->rawObjectMap($raw['examples']) : null,
            content: array_key_exists('content', $raw) && is_array($raw['content']) ? $this->mediaTypeMap($raw['content']) : null,
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function requestBody(array $raw): RequestBodyNode
    {
        $rawContent = $raw['content'] ?? null;

        return new RequestBodyNode(
            content: is_array($rawContent) ? $this->mediaTypeMap($rawContent) : [],
            description: $this->stringOrNull($raw['description'] ?? null),
            required: $this->boolOrNull($raw['required'] ?? null),
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function responses(array $raw): ResponsesNode
    {
        $responses = [];
        $extensions = [];

        foreach ($raw as $statusCode => $value) {
            if (is_string($statusCode) && str_starts_with($statusCode, 'x-')) {
                $extensions[$statusCode] = $value;

                continue;
            }
            if (! is_array($value)) {
                continue;
            }
            $responses[$statusCode] = $this->isReference($value)
                ? $this->reference($value)
                : $this->response($value);
        }

        return new ResponsesNode($responses, $extensions);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function response(array $raw): ResponseNode
    {
        $rawContent = $raw['content'] ?? null;

        return new ResponseNode(
            description: $this->stringOrNull($raw['description'] ?? null),
            content: is_array($rawContent) ? $this->mediaTypeMap($rawContent) : [],
            headers: array_key_exists('headers', $raw) ? $this->rawObjectMap($raw['headers']) : null,
            links: array_key_exists('links', $raw) ? $this->rawObjectMap($raw['links']) : null,
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, MediaTypeNode>
     */
    private function mediaTypeMap(array $raw): array
    {
        $content = [];
        foreach ($raw as $mediaType => $value) {
            if (is_array($value)) {
                $content[(string) $mediaType] = $this->mediaType($value);
            }
        }

        return $content;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function mediaType(array $raw): MediaTypeNode
    {
        return new MediaTypeNode(
            schema: $this->subschema($raw['schema'] ?? null, 0),
            example: $raw['example'] ?? null,
            examples: array_key_exists('examples', $raw) ? $this->rawObjectMap($raw['examples']) : null,
            encoding: array_key_exists('encoding', $raw) ? $this->rawObjectMap($raw['encoding']) : null,
            itemSchema: $this->subschema($raw['itemSchema'] ?? null, 0),
            extensions: $this->extensions($raw),
        );
    }

    /**
     * A `security` value (root or operation level). Presence (null vs []) is
     * the CALLER's concern via array_key_exists; this only shapes the value.
     * A mistyped value hydrates to an empty list, never crashes.
     *
     * @return list<SecurityRequirementNode>
     */
    private function securityList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $requirements = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $requirements[] = $this->securityRequirement($entry);
            }
        }

        return $requirements;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function securityRequirement(array $raw): SecurityRequirementNode
    {
        $schemes = [];
        foreach ($raw as $scheme => $scopes) {
            $schemes[(string) $scheme] = is_array($scopes)
                ? array_values(array_filter($scopes, is_string(...)))
                : [];
        }

        return new SecurityRequirementNode($schemes);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function components(array $raw): ComponentsNode
    {
        $schemas = [];
        $rawSchemas = $raw['schemas'] ?? null;
        if (is_array($rawSchemas)) {
            foreach ($rawSchemas as $name => $value) {
                $node = $this->subschema($value, 0);
                if ($node !== null) {
                    $schemas[(string) $name] = $node;
                }
            }
        }

        $responses = [];
        $rawResponses = $raw['responses'] ?? null;
        if (is_array($rawResponses)) {
            foreach ($rawResponses as $name => $value) {
                if (! is_array($value)) {
                    continue;
                }
                $responses[(string) $name] = $this->isReference($value)
                    ? $this->reference($value)
                    : $this->response($value);
            }
        }

        $parameters = [];
        $rawParameters = $raw['parameters'] ?? null;
        if (is_array($rawParameters)) {
            foreach ($rawParameters as $name => $value) {
                if (! is_array($value)) {
                    continue;
                }
                $parameters[(string) $name] = $this->isReference($value)
                    ? $this->reference($value)
                    : $this->parameter($value);
            }
        }

        $requestBodies = [];
        $rawBodies = $raw['requestBodies'] ?? null;
        if (is_array($rawBodies)) {
            foreach ($rawBodies as $name => $value) {
                if (! is_array($value)) {
                    continue;
                }
                $requestBodies[(string) $name] = $this->isReference($value)
                    ? $this->reference($value)
                    : $this->requestBody($value);
            }
        }

        $securitySchemes = [];
        $rawSchemes = $raw['securitySchemes'] ?? null;
        if (is_array($rawSchemes)) {
            foreach ($rawSchemes as $name => $value) {
                $scheme = $this->rawObjectMap($value);
                if ($scheme !== null) {
                    $securitySchemes[(string) $name] = $scheme;
                }
            }
        }

        $extra = [];
        $typed = ['schemas', 'responses', 'parameters', 'requestBodies', 'securitySchemes'];
        foreach ($raw as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'x-')) {
                continue;
            }
            if (! in_array($key, $typed, true)) {
                $extra[(string) $key] = $value;
            }
        }

        return new ComponentsNode(
            schemas: $schemas,
            responses: $responses,
            parameters: $parameters,
            requestBodies: $requestBodies,
            securitySchemes: $securitySchemes,
            extensions: $this->extensions($raw),
            extra: $extra,
        );
    }

    /**
     * Hydrate a value in schema position: an object schema, a `$ref`, or a
     * boolean JSON Schema (3.1). `true` is the empty schema ("anything"),
     * `false` hydrates to its exact JSON-Schema equivalent `{"not": {}}`
     * ("nothing"). Anything else (a mistyped value) returns null and the
     * caller routes the raw value to its extra bag or skips the entry.
     */
    private function subschema(mixed $value, int $depth): SchemaNode|ReferenceNode|null
    {
        if (is_bool($value)) {
            return $value ? new SchemaNode : new SchemaNode(not: new SchemaNode);
        }

        if (! is_array($value)) {
            return null;
        }

        return $this->isReference($value)
            ? $this->reference($value)
            : $this->schema($value, $depth);
    }

    /**
     * The big one: hydrate a Schema Object, applying every normalization
     * rewrite and giving each keyword the emitter consumes a typed home.
     * Mistyped values land in `extra` verbatim, so no spec information is
     * destroyed and the emitter's "ignore what fails the type check" behavior
     * is preserved exactly.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private function schema(array $raw, int $depth): SchemaNode
    {
        if ($depth > $this->maxDepth) {
            throw new ParseException("OpenAPI document exceeds the maximum schema nesting depth ({$this->maxDepth}).");
        }

        // Closed tuple (issue #82): `items: false` next to a non-empty
        // `prefixItems` list pins the maximum length at the tuple size, so a
        // `maxItems` of that size is synthesized (or a looser/malformed bound
        // tightened) BEFORE the boolean `items` is dropped below, including
        // the numeric-string read of an existing bound (issue #32).
        if (($raw['items'] ?? null) === false) {
            $prefixItems = $raw['prefixItems'] ?? null;
            if (is_array($prefixItems) && $prefixItems !== [] && array_is_list($prefixItems)) {
                $size = count($prefixItems);
                $existing = $raw['maxItems'] ?? null;
                if (is_string($existing) && is_numeric($existing)) {
                    $existing = $this->numericFromString($existing);
                }
                if (! (is_int($existing) || is_float($existing)) || $existing > $size) {
                    $raw['maxItems'] = $size;
                }
            }
        }

        $type = null;
        $strings = ['format' => null, 'title' => null, 'description' => null, 'pattern' => null, 'contentMediaType' => null];
        $bools = ['nullable' => null, 'deprecated' => null, 'readOnly' => null, 'writeOnly' => null, 'uniqueItems' => null];
        $required = null;
        $enum = null;
        $numbers = ['multipleOf' => null, 'minimum' => null, 'maximum' => null];
        $bounds = ['exclusiveMinimum' => null, 'exclusiveMaximum' => null];
        $int = ['minLength' => null, 'maxLength' => null, 'minItems' => null, 'maxItems' => null, 'minProperties' => null, 'maxProperties' => null];
        $lists = ['allOf' => null, 'oneOf' => null, 'anyOf' => null, 'prefixItems' => null];
        $properties = null;
        $additionalProperties = null;
        $hasAdditionalProperties = false;
        $patternProperties = null;
        $not = null;
        $items = null;
        $dependentRequired = null;
        $default = null;
        $hasDefault = false;
        $const = null;
        $hasConst = false;
        $discriminator = null;
        $example = null;
        $xDeprecatedReason = null;
        $xDeprecationReason = null;
        $extensions = [];
        $extra = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'x-')) {
                $extensions[$key] = $value;
                if ($key === 'x-deprecated-reason' && is_string($value)) {
                    $xDeprecatedReason = $value;
                }
                if ($key === 'x-deprecation-reason' && is_string($value)) {
                    $xDeprecationReason = $value;
                }

                continue;
            }

            if (in_array($key, self::INT_KEYS, true)) {
                $coerced = $this->intValue($value);
                if ($coerced === null) {
                    $extra[(string) $key] = $value;
                } else {
                    $int[$key] = $coerced;
                }

                continue;
            }

            switch ($key) {
                case 'type':
                    if (is_string($value)) {
                        $type = $value;
                    } elseif (is_array($value)) {
                        $type = array_values(array_filter($value, is_string(...)));
                    } else {
                        $extra['type'] = $value;
                    }
                    break;
                case 'format':
                case 'title':
                case 'description':
                case 'pattern':
                case 'contentMediaType':
                    if (is_string($value)) {
                        $strings[$key] = $value;
                    } else {
                        $extra[(string) $key] = $value;
                    }
                    break;
                case 'nullable':
                    // Normalization (issue #33): the strings "true"/"false"
                    // (case-insensitive) coerce to booleans; any other
                    // non-boolean stays raw (not-nullable).
                    $coerced = is_string($value) ? ['true' => true, 'false' => false][strtolower($value)] ?? null : null;
                    if (is_bool($value)) {
                        $bools['nullable'] = $value;
                    } elseif ($coerced !== null) {
                        $bools['nullable'] = $coerced;
                    } else {
                        $extra['nullable'] = $value;
                    }
                    break;
                case 'deprecated':
                case 'readOnly':
                case 'writeOnly':
                case 'uniqueItems':
                    if (is_bool($value)) {
                        $bools[$key] = $value;
                    } else {
                        $extra[(string) $key] = $value;
                    }
                    break;
                case 'required':
                    // The boolean form is the tolerated real-world misuse
                    // (`required: true` on a property schema) and survives so
                    // the emitter can warn about it.
                    if (is_bool($value)) {
                        $required = $value;
                    } elseif (is_array($value)) {
                        $required = array_values(array_filter($value, is_string(...)));
                    } else {
                        $extra['required'] = $value;
                    }
                    break;
                case 'enum':
                    if (is_array($value)) {
                        $enum = array_values($value);
                    } else {
                        $extra['enum'] = $value;
                    }
                    break;
                case 'multipleOf':
                case 'minimum':
                case 'maximum':
                    $coerced = $this->numberValue($value);
                    if ($coerced === null) {
                        $extra[(string) $key] = $value;
                    } else {
                        $numbers[$key] = $coerced;
                    }
                    break;
                case 'exclusiveMinimum':
                case 'exclusiveMaximum':
                    // Both spec forms survive verbatim: the 3.0 boolean
                    // companion and the 3.1 numeric bound (with the
                    // numeric-string coercion of issue #32).
                    $coerced = is_bool($value) ? $value : $this->numberValue($value);
                    if ($coerced === null) {
                        $extra[(string) $key] = $value;
                    } else {
                        $bounds[$key] = $coerced;
                    }
                    break;
                case 'properties':
                    $properties = $this->schemaMap($value, $depth, $extra, 'properties');
                    break;
                case 'patternProperties':
                    $patternProperties = $this->schemaMap($value, $depth, $extra, 'patternProperties');
                    break;
                case 'additionalProperties':
                    if (is_bool($value)) {
                        $additionalProperties = $value;
                        $hasAdditionalProperties = true;
                        break;
                    }
                    $node = $this->subschema($value, $depth + 1);
                    if ($node === null) {
                        $extra['additionalProperties'] = $value;
                    } else {
                        $additionalProperties = $node;
                        $hasAdditionalProperties = true;
                    }
                    break;
                case 'allOf':
                case 'oneOf':
                case 'anyOf':
                    $list = $this->schemaList($value, $depth);
                    if ($list === null) {
                        $extra[(string) $key] = $value;
                    } else {
                        $lists[$key] = $list;
                    }
                    break;
                case 'prefixItems':
                    // Unlike the composition lists, prefixItems is POSITIONAL:
                    // rules attach to tuple indexes, so a malformed entry must
                    // not shift the later positions down. It hydrates to an
                    // empty placeholder node (which emits no rules, exactly
                    // like the skipped null position on the old cebe path)
                    // instead of being dropped.
                    $list = $this->prefixItemList($value, $depth);
                    if ($list === null) {
                        $extra['prefixItems'] = $value;
                    } else {
                        $lists['prefixItems'] = $list;
                    }
                    break;
                case 'not':
                    $not = $this->subschema($value, $depth + 1);
                    if ($not === null) {
                        $extra['not'] = $value;
                    }
                    break;
                case 'items':
                    // Normalization (issue #20): `items: true` is the empty
                    // schema ("any"); `items: false` is dropped, its
                    // closed-tuple bound already synthesized above.
                    if ($value === true) {
                        $items = new SchemaNode;
                    } elseif ($value === false) {
                        // Dropped.
                    } else {
                        $items = $this->subschema($value, $depth + 1);
                        if ($items === null) {
                            $extra['items'] = $value;
                        }
                    }
                    break;
                case 'dependentRequired':
                    if (is_array($value)) {
                        $dependentRequired = [];
                        foreach ($value as $property => $dependents) {
                            if (is_array($dependents)) {
                                $dependentRequired[(string) $property] = array_values(array_filter($dependents, is_string(...)));
                            }
                        }
                    } else {
                        $extra['dependentRequired'] = $value;
                    }
                    break;
                case 'default':
                    $default = $value;
                    $hasDefault = true;
                    break;
                case 'const':
                    $const = $value;
                    $hasConst = true;
                    break;
                case 'discriminator':
                    $discriminator = is_array($value) ? $this->discriminator($value) : null;
                    if ($discriminator === null) {
                        $extra['discriminator'] = $value;
                    }
                    break;
                case 'example':
                    $example = $value;
                    break;
                default:
                    $extra[(string) $key] = $value;
            }
        }

        return new SchemaNode(
            type: $type,
            format: $strings['format'],
            title: $strings['title'],
            description: $strings['description'],
            nullable: $bools['nullable'],
            deprecated: $bools['deprecated'],
            readOnly: $bools['readOnly'],
            writeOnly: $bools['writeOnly'],
            required: $required,
            enum: $enum,
            multipleOf: $numbers['multipleOf'],
            minimum: $numbers['minimum'],
            maximum: $numbers['maximum'],
            exclusiveMinimum: $bounds['exclusiveMinimum'],
            exclusiveMaximum: $bounds['exclusiveMaximum'],
            minLength: $int['minLength'],
            maxLength: $int['maxLength'],
            pattern: $strings['pattern'],
            minItems: $int['minItems'],
            maxItems: $int['maxItems'],
            uniqueItems: $bools['uniqueItems'],
            minProperties: $int['minProperties'],
            maxProperties: $int['maxProperties'],
            properties: $properties,
            additionalProperties: $additionalProperties,
            hasAdditionalProperties: $hasAdditionalProperties,
            patternProperties: $patternProperties,
            allOf: $lists['allOf'],
            oneOf: $lists['oneOf'],
            anyOf: $lists['anyOf'],
            not: $not,
            items: $items,
            prefixItems: $lists['prefixItems'],
            dependentRequired: $dependentRequired,
            default: $default,
            hasDefault: $hasDefault,
            const: $const,
            hasConst: $hasConst,
            contentMediaType: $strings['contentMediaType'],
            discriminator: $discriminator,
            example: $example,
            xDeprecatedReason: $xDeprecatedReason,
            xDeprecationReason: $xDeprecationReason,
            extensions: $extensions,
            extra: $extra,
        );
    }

    /**
     * A `properties` / `patternProperties` style map. A non-array value goes
     * to `extra` whole; a mistyped entry inside an otherwise valid map is
     * skipped.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, SchemaNode|ReferenceNode>|null
     */
    private function schemaMap(mixed $value, int $depth, array &$extra, string $key): ?array
    {
        if (! is_array($value)) {
            $extra[$key] = $value;

            return null;
        }

        $map = [];
        foreach ($value as $name => $entry) {
            $node = $this->subschema($entry, $depth + 1);
            if ($node !== null) {
                $map[(string) $name] = $node;
            }
        }

        return $map;
    }

    /**
     * An `allOf` / `oneOf` / `anyOf` / `prefixItems` style list. Returns null
     * for a non-list value (routed to `extra` by the caller); mistyped
     * entries inside a valid list are skipped.
     *
     * @return list<SchemaNode|ReferenceNode>|null
     */
    private function schemaList(mixed $value, int $depth): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $entry) {
            $node = $this->subschema($entry, $depth + 1);
            if ($node !== null) {
                $list[] = $node;
            }
        }

        return $list;
    }

    /**
     * The `prefixItems` tuple list. Returns null for a non-list value (routed
     * to `extra` by the caller). A mistyped entry inside a valid list becomes
     * an empty placeholder SchemaNode rather than being skipped: positions
     * are load-bearing (rules attach to tuple indexes), so the later entries
     * must keep their index, and a non-empty list, even of placeholders,
     * still marks the schema as a tuple (suppressing the post-prefix `items`
     * wildcard rules). Mirrors the skipped-null behavior of the old cebe path.
     *
     * @return list<SchemaNode|ReferenceNode>|null
     */
    private function prefixItemList(mixed $value, int $depth): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $entry) {
            $list[] = $this->subschema($entry, $depth + 1) ?? new SchemaNode;
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function discriminator(array $raw): ?DiscriminatorNode
    {
        $propertyName = $raw['propertyName'] ?? null;
        if (! is_string($propertyName)) {
            return null;
        }

        $mapping = null;
        $rawMapping = $raw['mapping'] ?? null;
        if (is_array($rawMapping)) {
            $mapping = [];
            foreach ($rawMapping as $valueKey => $target) {
                if (is_string($target)) {
                    $mapping[(string) $valueKey] = $target;
                }
            }
        }

        return new DiscriminatorNode(
            propertyName: $propertyName,
            mapping: $mapping,
            defaultMapping: $this->stringOrNull($raw['defaultMapping'] ?? null),
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function reference(array $raw): ReferenceNode
    {
        $ref = $raw['$ref'] ?? null;

        return new ReferenceNode(
            ref: is_string($ref) ? $ref : '',
            summary: $this->stringOrNull($raw['summary'] ?? null),
            description: $this->stringOrNull($raw['description'] ?? null),
            extensions: $this->extensions($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function isReference(array $raw): bool
    {
        return isset($raw['$ref']) && is_string($raw['$ref']);
    }

    /**
     * One warning per 3.2-only construct occurrence the generator silently
     * drops (issue #103). The reader hydrates these constructs into their
     * typed stub properties, but the generated output still drops them, so
     * the warnings stay accurate.
     *
     * @param  array<array-key, mixed>  $raw
     * @return list<string>
     */
    private function scanDropped32Constructs(array $raw): array
    {
        $warnings = [];

        foreach (['paths', 'webhooks'] as $section) {
            $items = $raw[$section] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $route => $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (array_key_exists('query', $item)) {
                    $warnings[] = sprintf(
                        'OpenAPI 3.2 `query` operation at %s.%s was dropped: QUERY routes are not generated yet. Tracked in issue #102 (%s).',
                        $section,
                        (string) $route,
                        self::ISSUE_102_URL,
                    );
                }

                if (array_key_exists('additionalOperations', $item)) {
                    $warnings[] = sprintf(
                        'OpenAPI 3.2 `additionalOperations` at %s.%s were dropped: custom-method routes are not generated yet. Tracked in issue #102 (%s).',
                        $section,
                        (string) $route,
                        self::ISSUE_102_URL,
                    );
                }
            }
        }

        $this->scanItemSchemas($raw, '', $warnings);

        return $warnings;
    }

    /**
     * Recursively find the 3.2 `itemSchema` Media Type member (sequential
     * media types such as JSON Lines): any `content` map whose media-type
     * entry carries an `itemSchema` key. The walk is bounded by the same
     * maxDepth as the hydration path, but since this is a best-effort warning
     * scan over raw data it stops descending silently instead of throwing.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $warnings
     */
    private function scanItemSchemas(array $node, string $trail, array &$warnings, int $depth = 0): void
    {
        if ($depth > $this->maxDepth) {
            return;
        }

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $here = $trail === '' ? (string) $key : $trail.'.'.$key;

            if ($key === 'content') {
                foreach ($value as $mediaType => $media) {
                    if (is_array($media) && array_key_exists('itemSchema', $media)) {
                        $warnings[] = sprintf(
                            'OpenAPI 3.2 `itemSchema` at %s.%s was dropped: sequential media types are not read yet. Tracked in issue #102 (%s).',
                            $here,
                            (string) $mediaType,
                            self::ISSUE_102_URL,
                        );
                    }
                }
            }

            $this->scanItemSchemas($value, $here, $warnings, $depth + 1);
        }
    }

    /**
     * The `x-*` vendor extension keys of an object, raw passthrough.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private function extensions(array $raw): array
    {
        $extensions = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'x-')) {
                $extensions[$key] = $value;
            }
        }

        return $extensions;
    }

    /**
     * A raw passthrough object: an untyped spec object the emitter does not
     * consume (contact, license, encoding, headers, ...), kept as a
     * string-keyed array. Null for a mistyped (non-array) value.
     *
     * @return array<string, mixed>|null
     */
    private function rawObjectMap(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $map = [];
        foreach ($value as $key => $entry) {
            $map[(string) $key] = $entry;
        }

        return $map;
    }

    /**
     * A raw passthrough list of objects (tags, servers): array entries are
     * kept as string-keyed arrays, anything else is skipped. Null for a
     * mistyped (non-array) value.
     *
     * @return list<array<string, mixed>>|null
     */
    private function rawObjectList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $entry) {
            $object = $this->rawObjectMap($entry);
            if ($object !== null) {
                $list[] = $object;
            }
        }

        return $list;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * A number-valued keyword: int and float pass through, a strictly-numeric
     * string is coerced (issue #32), anything else is null (routed to `extra`
     * by the caller).
     */
    private function numberValue(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return $this->numericFromString($value);
        }

        return null;
    }

    /**
     * An integer-valued keyword (lengths, counts). The emitter only honors
     * `is_int` values for these, so a float (native or coerced from a
     * fractional string) is rejected here and lands in `extra`, preserving
     * today's "silently ignored" behavior with the raw value retained.
     */
    private function intValue(mixed $value): ?int
    {
        $number = $this->numberValue($value);

        return is_int($number) ? $number : null;
    }

    /**
     * Cast a strictly-numeric string to int when it has no fractional part and
     * fits an int, else to float. Mirrors how a JSON number would have
     * decoded: `"8"` to int 8, `"0.5"` to float 0.5.
     */
    private function numericFromString(string $value): int|float
    {
        $float = (float) $value;

        // An integer-shaped string (no decimal point, no exponent) that fits
        // the platform int range becomes an int; a fractional value, an
        // exponent, or an out-of-range magnitude stays a float. The range is
        // checked before any int cast, and the cast is done on the string,
        // not the float: casting an out-of-range float to int both warns and
        // truncates.
        if (
            ! str_contains($value, '.')
            && stripos($value, 'e') === false
            && $float >= (float) PHP_INT_MIN
            && $float <= (float) PHP_INT_MAX
        ) {
            return (int) $value;
        }

        return $float;
    }
}
