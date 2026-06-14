<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser\Fidelity;

use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;

/**
 * Walks the raw decoded spec array once and records every construct the
 * generator cannot faithfully represent in the generated code AND whose dropping
 * changes the CORRECTNESS or runtime behavior of that code. The scan runs over
 * the raw array (not the typed graph) for two reasons: it has the exact key
 * order to build RFC 6901 JSON pointers, and it sees keywords the typed graph
 * does not retain (encoding, patternProperties, allowEmptyValue).
 *
 * This is the consolidation point for the fidelity report. It deliberately
 * mirrors, in one deterministic pass, the gaps the emitters surface as scattered
 * human warnings, plus the silent gaps that had no warning at all, so the report
 * is a single authoritative list. The scan is bounded by the same depth ceiling
 * the hydration path uses and stops descending silently rather than throwing:
 * this is a best-effort diagnostic, never a hard failure.
 *
 * Scope discipline: a construct is recorded ONLY when its loss changes
 * validation or runtime behavior. Pure metadata with no codegen effect (Link
 * objects, allowReserved, externalDocs, examples, xml, servers, info contact /
 * license) is intentionally not recorded, so the report stays signal, not noise.
 *
 * @internal
 */
final class FidelityScanner
{
    /**
     * Mirrors {@see OpenApiReader::DEFAULT_MAX_DEPTH}.
     * The schema walk stops descending past this depth instead of throwing, so a
     * hostile deeply-nested spec cannot turn a diagnostic scan into a stack
     * overflow.
     */
    private const MAX_DEPTH = 512;

    /**
     * The HTTP method keys an operation can sit under in a path item. `query` is
     * the OpenAPI 3.2 QUERY method; the 3.2 `query`/`additionalOperations`
     * constructs are reported by the reader's own scan, so this list is used
     * only to locate operation objects for parameter/response/body inspection.
     *
     * @var list<string>
     */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @param  array<array-key, mixed>  $raw  the decoded spec document
     * @return list<FidelityEntry>
     */
    public function scan(array $raw): array
    {
        $report = new FidelityReport;

        $this->scanPaths($raw, $report);
        $this->scanComponents($raw, $report);
        $this->scanWebhooks($raw, $report);

        return $report->entries();
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function scanPaths(array $raw, FidelityReport $report): void
    {
        $paths = $raw['paths'] ?? null;
        if (! is_array($paths)) {
            return;
        }

        foreach ($paths as $route => $item) {
            if (! is_array($item)) {
                continue;
            }

            $route = (string) $route;
            $pathPointer = '#/paths/'.$this->token($route);

            // Path-item-level parameters apply to every operation under the path.
            $this->scanParameterList($item['parameters'] ?? null, $pathPointer.'/parameters', sprintf('%s (path item)', $route), $report);

            // OpenAPI 3.2 QUERY operation and custom-method additionalOperations:
            // neither is generated (no route, no controller method).
            if (array_key_exists('query', $item)) {
                $report->record(
                    $pathPointer.'/query',
                    $route.' (QUERY)',
                    'OpenAPI 3.2 query operation',
                    'the QUERY operation produces no route or controller method, it is dropped from the output',
                );
            }
            if (array_key_exists('additionalOperations', $item)) {
                $report->record(
                    $pathPointer.'/additionalOperations',
                    $route.' (additionalOperations)',
                    'OpenAPI 3.2 additionalOperations',
                    'custom-method operations produce no route or controller method, they are dropped from the output',
                );
            }

            foreach ($item as $method => $operation) {
                $method = strtolower((string) $method);
                if (! in_array($method, self::HTTP_METHODS, true) || ! is_array($operation)) {
                    continue;
                }

                $label = strtoupper($method).' '.$route;
                $operationPointer = $pathPointer.'/'.$this->token($method);

                $this->scanParameterList($operation['parameters'] ?? null, $operationPointer.'/parameters', $label, $report);
                $this->scanRequestBody($operation['requestBody'] ?? null, $operationPointer.'/requestBody', $label, $report);
                $this->scanCallbacks($operation['callbacks'] ?? null, $operationPointer.'/callbacks', $label, $report);
                $this->scanResponses($operation['responses'] ?? null, $operationPointer.'/responses', $label, $report);
            }
        }
    }

    /**
     * Operation callbacks: the reader keeps them as raw passthrough, no emitter
     * generates handler scaffolding for them.
     */
    private function scanCallbacks(mixed $callbacks, string $pointer, string $label, FidelityReport $report): void
    {
        if (is_array($callbacks) && $callbacks !== []) {
            $report->record(
                $pointer,
                $label,
                'operation callbacks',
                'callbacks generate no handler scaffolding, the whole callback interaction surface is dropped',
            );
        }
    }

    /**
     * Response headers on a success (2xx) response: the reader parses them but
     * no emitter generates typing or emission. Only 2xx responses are reported,
     * mirroring the emitter that consumes only the selected success response;
     * headers on error responses describe output the scaffold never touches.
     */
    private function scanResponses(mixed $responses, string $pointer, string $label, FidelityReport $report): void
    {
        if (! is_array($responses)) {
            return;
        }

        foreach ($responses as $status => $response) {
            $status = (string) $status;
            if (! is_array($response) || isset($response['$ref'])) {
                continue;
            }
            if ($status === '' || $status[0] !== '2') {
                continue;
            }

            $headers = $response['headers'] ?? null;
            if (is_array($headers) && $headers !== []) {
                $report->record(
                    $pointer.'/'.$this->token($status).'/headers',
                    sprintf('%s, %s response', $label, $status),
                    'success response headers',
                    'response headers are not generated, the documented headers are not emitted or typed',
                );
            }
        }
    }

    /**
     * Root webhooks (OpenAPI 3.1+): hydrated but not consumed, no handler
     * scaffolding is generated for them.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private function scanWebhooks(array $raw, FidelityReport $report): void
    {
        $webhooks = $raw['webhooks'] ?? null;
        if (! is_array($webhooks) || $webhooks === []) {
            return;
        }

        foreach ($webhooks as $name => $webhook) {
            if (! is_array($webhook)) {
                continue;
            }
            $name = (string) $name;
            $report->record(
                '#/webhooks/'.$this->token($name),
                sprintf("webhook '%s'", $name),
                'root webhook',
                'webhooks generate no handler scaffolding, the webhook interaction surface is dropped',
            );
        }
    }

    /**
     * @param  mixed  $parameters  the raw parameters array of an operation or path item
     */
    private function scanParameterList(mixed $parameters, string $basePointer, string $label, FidelityReport $report): void
    {
        if (! is_array($parameters)) {
            return;
        }

        foreach ($parameters as $index => $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $this->scanParameter($parameter, $basePointer.'/'.$this->token((string) $index), $label, $report);
        }
    }

    /**
     * @param  array<array-key, mixed>  $parameter
     */
    private function scanParameter(array $parameter, string $pointer, string $label, FidelityReport $report): void
    {
        // A $ref parameter is resolved (and reported, if it degrades) by the
        // emitter; the target component is scanned where it is declared.
        if (isset($parameter['$ref'])) {
            return;
        }

        $in = is_string($parameter['in'] ?? null) ? $parameter['in'] : '';
        $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : '';
        $where = sprintf("%s, %s parameter '%s'", $label, $in === '' ? 'unknown-location' : $in, $name);

        // A content-typed parameter (a media-type object instead of a schema) is
        // not validated against its schema: the synthesizer skips it.
        if (isset($parameter['content']) && ! isset($parameter['schema'])) {
            $report->record(
                $pointer,
                $where,
                'content-typed parameter (media-type object instead of schema)',
                'the parameter is not validated against its schema, it is read as a raw value',
            );
        }

        // allowEmptyValue is not enforced: an empty value is accepted or
        // rejected by the spec-derived rules alone, never by this keyword.
        if (($parameter['allowEmptyValue'] ?? null) === true) {
            $report->record(
                $pointer,
                $where,
                'allowEmptyValue on a parameter',
                'allowEmptyValue is not enforced, an empty value is governed only by the other rules',
            );
        }

        if ($in === 'cookie') {
            $report->record(
                $pointer,
                $where,
                'cookie parameter',
                'cookie parameters are not typed or validated, the value is read straight from the request',
            );
        }

        // A repeated-key array query parameter (the form/explode:true default)
        // collapses to a single value in PHP, so only the last value survives.
        if ($in === 'query' && $this->isRepeatedKeyArrayQuery($parameter)) {
            $report->record(
                $pointer,
                $where,
                'repeated-key array query parameter (form, explode:true)',
                'only the last value survives, array elements are silently lost',
            );
        }

        // A matrix/label style path parameter is validated against the raw,
        // style-encoded segment, not the decoded value.
        if ($in === 'path') {
            $style = is_string($parameter['style'] ?? null) ? $parameter['style'] : '';
            if ($style === 'matrix' || $style === 'label') {
                $report->record(
                    $pointer,
                    $where,
                    sprintf('style: %s path parameter', $style),
                    'the parameter is validated against the raw style-encoded segment, not the decoded value',
                );
            }
        }

        // The parameter's own schema may carry correctness gaps (not, int32/int64,
        // undiscriminated unions, $ref-valued map values, patternProperties).
        if (is_array($parameter['schema'] ?? null)) {
            $this->scanSchema($parameter['schema'], $pointer.'/schema', $where, $report);
        }
    }

    /**
     * @param  array<array-key, mixed>  $parameter
     */
    private function isRepeatedKeyArrayQuery(array $parameter): bool
    {
        $schema = $parameter['schema'] ?? null;
        if (! is_array($schema) || ($schema['type'] ?? null) !== 'array') {
            return false;
        }

        // form + explode:true is the OpenAPI query default. explode defaults to
        // true for the form style, so an absent explode counts as the default.
        $style = is_string($parameter['style'] ?? null) ? $parameter['style'] : 'form';
        if ($style !== 'form') {
            return false;
        }
        $explode = $parameter['explode'] ?? true;

        return $explode === true;
    }

    /**
     * @param  mixed  $requestBody  the raw requestBody of an operation
     */
    private function scanRequestBody(mixed $requestBody, string $pointer, string $label, FidelityReport $report): void
    {
        if (! is_array($requestBody) || isset($requestBody['$ref'])) {
            return;
        }

        $content = $requestBody['content'] ?? null;
        if (! is_array($content)) {
            return;
        }

        foreach ($content as $mediaType => $media) {
            $mediaType = (string) $mediaType;
            if (! is_array($media)) {
                continue;
            }

            $mediaPointer = $pointer.'/content/'.$this->token($mediaType);

            // OpenAPI 3.2 sequential media types (JSON Lines and friends): the
            // itemSchema member is not read, so the streamed item shape is not
            // validated.
            if (array_key_exists('itemSchema', $media)) {
                $report->record(
                    $mediaPointer.'/itemSchema',
                    sprintf("%s, request body media type '%s'", $label, $mediaType),
                    'OpenAPI 3.2 itemSchema (sequential media type)',
                    'the per-item schema of a sequential media type is not read, streamed items are not validated',
                );
            }

            // The multipart encoding object (contentType, per-property headers)
            // is not honored: only schema-level contentMediaType feeds mimetypes:.
            if (str_starts_with($mediaType, 'multipart/') && isset($media['encoding']) && is_array($media['encoding'])) {
                $report->record(
                    $mediaPointer.'/encoding',
                    sprintf("%s, request body media type '%s'", $label, $mediaType),
                    'multipart encoding object (contentType / per-property)',
                    'the encoding object is ignored, per-part content types and headers are not enforced',
                );
            }

            if (is_array($media['schema'] ?? null)) {
                $this->scanSchema($media['schema'], $mediaPointer.'/schema', sprintf("%s, request body media type '%s'", $label, $mediaType), $report);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function scanComponents(array $raw, FidelityReport $report): void
    {
        $components = $raw['components'] ?? null;
        if (! is_array($components)) {
            return;
        }

        $schemas = $components['schemas'] ?? null;
        if (! is_array($schemas)) {
            return;
        }

        foreach ($schemas as $name => $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $name = (string) $name;
            $this->scanSchema(
                $schema,
                '#/components/schemas/'.$this->token($name),
                sprintf("component schema '%s'", $name),
                $report,
            );
        }
    }

    /**
     * Recursively inspect a schema object for correctness-relevant constructs the
     * generator drops. The walk follows nested schemas (properties, items, the
     * composition keywords, additionalProperties, patternProperties) but never
     * follows a `$ref`: a referenced component is scanned where it is declared,
     * so the same gap is recorded once at its definition rather than at every use.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private function scanSchema(array $schema, string $pointer, string $where, FidelityReport $report, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH || isset($schema['$ref'])) {
            return;
        }

        $this->scanNotKeyword($schema, $pointer, $where, $report);
        $this->scanUndiscriminatedUnion($schema, $pointer, $where, $report);
        $this->scanPatternProperties($schema, $pointer, $where, $report);
        $this->scanRefValuedMap($schema, $pointer, $where, $report);

        $this->scanNestedSchemas($schema, $pointer, $where, $report, $depth);
    }

    /**
     * The `not` keyword is partially supported (the tractable subset is emitted
     * as a Rule::notIn): `not: {enum: [...]}` and `not: {const: X}` map cleanly
     * to a Laravel forbidden-value rule, so they are NOT recorded here. Every
     * OTHER `not` shape (a bare type exclusion, a nested object schema, a
     * composition) has no Laravel equivalent and IS recorded, so the report
     * still names the cases the generated code does not enforce.
     *
     * A literal `false` schema normalizes to `{"not": {}}` upstream; that empty
     * `not` is the closed-tuple machinery, not a user-authored forbidden shape,
     * so an empty object is treated as the supported (no-op) form and skipped.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private function scanNotKeyword(array $schema, string $pointer, string $where, FidelityReport $report): void
    {
        $not = $schema['not'] ?? null;
        if (! is_array($not) || $this->isTractableNot($not)) {
            return;
        }

        $report->record(
            $pointer.'/not',
            $where,
            'not (forbidden-shape) keyword',
            'the forbidden shape is not validated, a value matching it is wrongly accepted',
        );
    }

    /**
     * Whether a `not` subschema is the tractable enum/const form the rules
     * emitter now expresses as Rule::notIn, so the scanner must NOT record it.
     * An enum with at least one usable value, or a const, is tractable; an empty
     * `not` ({}) is the normalized closed-tuple no-op and is also treated as
     * tractable (nothing to enforce). Anything else (a type exclusion, a nested
     * shape) is intractable and stays in the report.
     *
     * @param  array<array-key, mixed>  $not
     */
    private function isTractableNot(array $not): bool
    {
        if ($not === []) {
            return true;
        }

        // An enum is tractable when it carries at least one usable scalar value
        // (string/int/float/bool), mirroring SchemaFacts::enumValues which the
        // rules emitter uses: those become Rule::notIn([...]). An enum of only
        // unusable members (e.g. [null]) emits no rule, so it stays recorded.
        $enum = $not['enum'] ?? null;
        if (is_array($enum)) {
            foreach ($enum as $value) {
                if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                    return true;
                }
            }
        }

        // A const is tractable only when the rules emitter actually emits it: a
        // string or int const becomes Rule::notIn([X]). A float/bool/array/null
        // const is NOT emitted (SchemaFacts::constValue returns null for it), so
        // it stays an intractable, recorded form, keeping the report honest.
        if (array_key_exists('const', $not)) {
            return is_string($not['const']) || is_int($not['const']);
        }

        return false;
    }

    /**
     * An object-shaped oneOf/anyOf without a discriminator cannot be hydrated to
     * a concrete variant: it is typed mixed and only its presence is validated.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private function scanUndiscriminatedUnion(array $schema, string $pointer, string $where, FidelityReport $report): void
    {
        if (isset($schema['discriminator'])) {
            return;
        }

        foreach (['oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (! is_array($members) || $members === []) {
                continue;
            }

            if ($this->unionHasObjectMember($members)) {
                $report->record(
                    $pointer.'/'.$keyword,
                    $where,
                    sprintf('undiscriminated object %s', $keyword),
                    'the union is typed mixed and only its presence is validated, no variant is hydrated or shape-checked',
                );
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private function unionHasObjectMember(array $members): bool
    {
        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }
            // A member that is a $ref, declares object type, or carries
            // properties is an object variant for our purposes; a union of pure
            // scalars hydrates as a native scalar union and is fully supported.
            if (isset($member['$ref']) || ($member['type'] ?? null) === 'object' || isset($member['properties'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * patternProperties value schemas are not validated, except where the
     * closed-object machinery already covers the keys. Recording the construct
     * here is the honest signal that the VALUES against a regex key are unchecked.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private function scanPatternProperties(array $schema, string $pointer, string $where, FidelityReport $report): void
    {
        $patternProperties = $schema['patternProperties'] ?? null;
        if (! is_array($patternProperties) || $patternProperties === []) {
            return;
        }

        $report->record(
            $pointer.'/patternProperties',
            $where,
            'patternProperties value schemas',
            'values matching a property-name pattern are not validated against their schema',
        );
    }

    /**
     * A $ref-valued additionalProperties map: the value type is typed in the
     * docblock but not deep-validated or hydrated into the referenced Data class.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private function scanRefValuedMap(array $schema, string $pointer, string $where, FidelityReport $report): void
    {
        $additional = $schema['additionalProperties'] ?? null;
        if (is_array($additional) && isset($additional['$ref']) && is_string($additional['$ref'])) {
            $report->record(
                $pointer.'/additionalProperties',
                $where,
                '$ref-valued additionalProperties map values',
                'map values are typed but not deep-validated or hydrated into the referenced Data class',
            );
        }
    }

    /**
     * Descend into the schemas nested under this one. Composition members carry
     * their array index in the pointer; map/array/property children carry their
     * key. A $ref child is not followed (it is scanned at its definition).
     *
     * @param  array<array-key, mixed>  $schema
     */
    private function scanNestedSchemas(array $schema, string $pointer, string $where, FidelityReport $report, int $depth): void
    {
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            $members = $schema[$keyword] ?? null;
            if (! is_array($members)) {
                continue;
            }
            foreach ($members as $index => $member) {
                if (is_array($member)) {
                    $this->scanSchema($member, $pointer.'/'.$this->token($keyword).'/'.$this->token((string) $index), $where, $report, $depth + 1);
                }
            }
        }

        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $name => $property) {
                if (is_array($property)) {
                    $this->scanSchema($property, $pointer.'/properties/'.$this->token((string) $name), $where, $report, $depth + 1);
                }
            }
        }

        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            $this->scanSchema($items, $pointer.'/items', $where, $report, $depth + 1);
        }

        $additional = $schema['additionalProperties'] ?? null;
        if (is_array($additional) && ! isset($additional['$ref'])) {
            $this->scanSchema($additional, $pointer.'/additionalProperties', $where, $report, $depth + 1);
        }
    }

    /**
     * Encode one path segment as an RFC 6901 reference token: '~' becomes '~0'
     * and '/' becomes '~1', in that order, so a literal '/pets' path segment
     * reads '~1pets' in the pointer. Done per segment, never on the assembled
     * pointer, so the structural '/' separators are preserved.
     */
    private function token(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
