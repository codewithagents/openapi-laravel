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
 * Throwaway scaffolding for the issue #104 Task 3 acceptance gate: serializes
 * the typed {@see OpenApiDocument} graph back into the raw array form the
 * cebe object model is constructed from, so the corpus comparison test can
 * run the UNCHANGED emitter over both the cebe path and the new reader path
 * and compare generated output byte-for-byte. If the reader dropped, moved,
 * or mistyped anything the emitter consumes (including the keywords read
 * through cebe's getSerializableData()), the round trip diverges and the gate
 * fails loudly.
 *
 * This is the exact inverse of {@see OpenApiReader}'s hydration: presence
 * flags reappear as key presence (hasDefault, hasConst,
 * hasAdditionalProperties, explicit `exclusiveMinimum: false`), the `extra`
 * bags restore mistyped and unknown keywords verbatim, and the `extensions`
 * bags restore every `x-*` key. Key insertion order inside maps the emitter
 * iterates (properties, paths, components) follows hydration order, which
 * follows spec order.
 *
 * Deleted (or repurposed into the migrated pipeline) in Task 7 together with
 * the cebe path; nothing outside the comparison gate may grow a dependency
 * on it.
 *
 * @internal
 */
final class SpecArraySerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(OpenApiDocument $document): array
    {
        $raw = [
            'openapi' => $document->openapi,
            'info' => self::info($document->info),
            'paths' => array_map(self::pathItem(...), $document->paths),
        ];

        if ($document->webhooks !== []) {
            $raw['webhooks'] = array_map(
                fn (PathItemNode|ReferenceNode $item): array => $item instanceof ReferenceNode
                    ? self::reference($item)
                    : self::pathItem($item),
                $document->webhooks,
            );
        }

        if ($document->components !== null) {
            $raw['components'] = self::components($document->components);
        }

        if ($document->security !== null) {
            $raw['security'] = array_map(self::securityRequirement(...), $document->security);
        }

        if ($document->tags !== null) {
            $raw['tags'] = $document->tags;
        }

        if ($document->servers !== null) {
            $raw['servers'] = $document->servers;
        }

        return [...$raw, ...$document->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function info(InfoNode $info): array
    {
        $raw = [];

        if ($info->title !== '') {
            $raw['title'] = $info->title;
        }
        if ($info->version !== '') {
            $raw['version'] = $info->version;
        }

        $raw = self::withOptional($raw, [
            'summary' => $info->summary,
            'description' => $info->description,
            'termsOfService' => $info->termsOfService,
            'contact' => $info->contact,
            'license' => $info->license,
        ]);

        return [...$raw, ...$info->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pathItem(PathItemNode $pathItem): array
    {
        $raw = [];

        if ($pathItem->ref !== null) {
            $raw['$ref'] = $pathItem->ref;
        }

        $raw = self::withOptional($raw, [
            'summary' => $pathItem->summary,
            'description' => $pathItem->description,
        ]);

        $methods = [
            'get' => $pathItem->get,
            'put' => $pathItem->put,
            'post' => $pathItem->post,
            'delete' => $pathItem->delete,
            'options' => $pathItem->options,
            'head' => $pathItem->head,
            'patch' => $pathItem->patch,
            'trace' => $pathItem->trace,
            'query' => $pathItem->query,
        ];

        foreach ($methods as $method => $operation) {
            if ($operation instanceof OperationNode) {
                $raw[$method] = self::operation($operation);
            }
        }

        if ($pathItem->additionalOperations !== null) {
            $raw['additionalOperations'] = array_map(self::operation(...), $pathItem->additionalOperations);
        }

        if ($pathItem->parameters !== []) {
            $raw['parameters'] = array_map(self::parameterOrReference(...), $pathItem->parameters);
        }

        if ($pathItem->servers !== null) {
            $raw['servers'] = $pathItem->servers;
        }

        return [...$raw, ...$pathItem->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function operation(OperationNode $operation): array
    {
        $raw = self::withOptional([], [
            'operationId' => $operation->operationId,
            'summary' => $operation->summary,
            'description' => $operation->description,
            'deprecated' => $operation->deprecated,
        ]);

        if ($operation->tags !== []) {
            $raw['tags'] = $operation->tags;
        }

        if ($operation->parameters !== []) {
            $raw['parameters'] = array_map(self::parameterOrReference(...), $operation->parameters);
        }

        if ($operation->requestBody !== null) {
            $raw['requestBody'] = $operation->requestBody instanceof ReferenceNode
                ? self::reference($operation->requestBody)
                : self::requestBody($operation->requestBody);
        }

        if ($operation->responses !== null) {
            $raw['responses'] = self::responses($operation->responses);
        }

        if ($operation->security !== null) {
            $raw['security'] = array_map(self::securityRequirement(...), $operation->security);
        }

        $raw = self::withOptional($raw, [
            'callbacks' => $operation->callbacks,
            'servers' => $operation->servers,
        ]);

        return [...$raw, ...$operation->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parameterOrReference(ParameterNode|ReferenceNode $parameter): array
    {
        return $parameter instanceof ReferenceNode
            ? self::reference($parameter)
            : self::parameter($parameter);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parameter(ParameterNode $parameter): array
    {
        $raw = [];

        if ($parameter->name !== '') {
            $raw['name'] = $parameter->name;
        }
        if ($parameter->in !== '') {
            $raw['in'] = $parameter->in;
        }

        $raw = self::withOptional($raw, [
            'description' => $parameter->description,
            'required' => $parameter->required,
            'deprecated' => $parameter->deprecated,
            'allowEmptyValue' => $parameter->allowEmptyValue,
            'style' => $parameter->style,
            'explode' => $parameter->explode,
            'allowReserved' => $parameter->allowReserved,
        ]);

        if ($parameter->schema !== null) {
            $raw['schema'] = self::schemaOrReference($parameter->schema);
        }

        if ($parameter->example !== null) {
            $raw['example'] = $parameter->example;
        }

        if ($parameter->examples !== null) {
            $raw['examples'] = $parameter->examples;
        }

        if ($parameter->content !== null) {
            $raw['content'] = array_map(self::mediaType(...), $parameter->content);
        }

        return [...$raw, ...$parameter->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestBody(RequestBodyNode $requestBody): array
    {
        $raw = self::withOptional([], [
            'description' => $requestBody->description,
            'required' => $requestBody->required,
        ]);

        if ($requestBody->content !== []) {
            $raw['content'] = array_map(self::mediaType(...), $requestBody->content);
        }

        return [...$raw, ...$requestBody->extensions];
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function responses(ResponsesNode $responses): array
    {
        $raw = [];

        foreach ($responses->responses as $statusCode => $response) {
            $raw[$statusCode] = $response instanceof ReferenceNode
                ? self::reference($response)
                : self::response($response);
        }

        // No spread here: numeric status code keys (canonicalized to int by
        // PHP) would be renumbered from zero by the spread operator.
        foreach ($responses->extensions as $key => $value) {
            $raw[$key] = $value;
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    private static function response(ResponseNode $response): array
    {
        $raw = self::withOptional([], [
            'description' => $response->description,
            'headers' => $response->headers,
        ]);

        if ($response->content !== []) {
            $raw['content'] = array_map(self::mediaType(...), $response->content);
        }

        if ($response->links !== null) {
            $raw['links'] = $response->links;
        }

        return [...$raw, ...$response->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mediaType(MediaTypeNode $mediaType): array
    {
        $raw = [];

        if ($mediaType->schema !== null) {
            $raw['schema'] = self::schemaOrReference($mediaType->schema);
        }

        if ($mediaType->example !== null) {
            $raw['example'] = $mediaType->example;
        }

        $raw = self::withOptional($raw, [
            'examples' => $mediaType->examples,
            'encoding' => $mediaType->encoding,
        ]);

        if ($mediaType->itemSchema !== null) {
            $raw['itemSchema'] = self::schemaOrReference($mediaType->itemSchema);
        }

        return [...$raw, ...$mediaType->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function components(ComponentsNode $components): array
    {
        $raw = [];

        if ($components->schemas !== []) {
            $raw['schemas'] = array_map(self::schemaOrReference(...), $components->schemas);
        }

        if ($components->responses !== []) {
            $raw['responses'] = array_map(
                fn (ResponseNode|ReferenceNode $response): array => $response instanceof ReferenceNode
                    ? self::reference($response)
                    : self::response($response),
                $components->responses,
            );
        }

        if ($components->parameters !== []) {
            $raw['parameters'] = array_map(self::parameterOrReference(...), $components->parameters);
        }

        if ($components->requestBodies !== []) {
            $raw['requestBodies'] = array_map(
                fn (RequestBodyNode|ReferenceNode $body): array => $body instanceof ReferenceNode
                    ? self::reference($body)
                    : self::requestBody($body),
                $components->requestBodies,
            );
        }

        if ($components->securitySchemes !== []) {
            $raw['securitySchemes'] = $components->securitySchemes;
        }

        return [...$raw, ...$components->extra, ...$components->extensions];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function securityRequirement(SecurityRequirementNode $requirement): array
    {
        return $requirement->schemes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemaOrReference(SchemaNode|ReferenceNode $node): array
    {
        return $node instanceof ReferenceNode
            ? self::reference($node)
            : self::schema($node);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(SchemaNode $schema): array
    {
        $raw = self::withOptional([], [
            'type' => $schema->type,
            'format' => $schema->format,
            'title' => $schema->title,
            'description' => $schema->description,
            'nullable' => $schema->nullable,
            'deprecated' => $schema->deprecated,
            'readOnly' => $schema->readOnly,
            'writeOnly' => $schema->writeOnly,
            'required' => $schema->required,
            'enum' => $schema->enum,
            'multipleOf' => $schema->multipleOf,
            'minimum' => $schema->minimum,
            'maximum' => $schema->maximum,
            'exclusiveMinimum' => $schema->exclusiveMinimum,
            'exclusiveMaximum' => $schema->exclusiveMaximum,
            'minLength' => $schema->minLength,
            'maxLength' => $schema->maxLength,
            'pattern' => $schema->pattern,
            'minItems' => $schema->minItems,
            'maxItems' => $schema->maxItems,
            'uniqueItems' => $schema->uniqueItems,
            'minProperties' => $schema->minProperties,
            'maxProperties' => $schema->maxProperties,
            'contentMediaType' => $schema->contentMediaType,
        ]);

        if ($schema->properties !== null) {
            $raw['properties'] = array_map(self::schemaOrReference(...), $schema->properties);
        }

        if ($schema->hasAdditionalProperties) {
            $raw['additionalProperties'] = is_bool($schema->additionalProperties)
                ? $schema->additionalProperties
                : ($schema->additionalProperties === null ? null : self::schemaOrReference($schema->additionalProperties));
        }

        if ($schema->patternProperties !== null) {
            $raw['patternProperties'] = array_map(self::schemaOrReference(...), $schema->patternProperties);
        }

        foreach (['allOf' => $schema->allOf, 'oneOf' => $schema->oneOf, 'anyOf' => $schema->anyOf, 'prefixItems' => $schema->prefixItems] as $keyword => $members) {
            if ($members !== null) {
                $raw[$keyword] = array_map(self::schemaOrReference(...), $members);
            }
        }

        if ($schema->not !== null) {
            $raw['not'] = self::schemaOrReference($schema->not);
        }

        if ($schema->items !== null) {
            $raw['items'] = self::schemaOrReference($schema->items);
        }

        if ($schema->dependentRequired !== null) {
            $raw['dependentRequired'] = $schema->dependentRequired;
        }

        if ($schema->hasDefault) {
            $raw['default'] = $schema->default;
        }

        if ($schema->hasConst) {
            $raw['const'] = $schema->const;
        }

        if ($schema->discriminator !== null) {
            $raw['discriminator'] = self::discriminator($schema->discriminator);
        }

        if ($schema->example !== null) {
            $raw['example'] = $schema->example;
        }

        return [...$raw, ...$schema->extra, ...$schema->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function discriminator(DiscriminatorNode $discriminator): array
    {
        $raw = ['propertyName' => $discriminator->propertyName];

        $raw = self::withOptional($raw, [
            'mapping' => $discriminator->mapping,
            'defaultMapping' => $discriminator->defaultMapping,
        ]);

        return [...$raw, ...$discriminator->extensions];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reference(ReferenceNode $reference): array
    {
        $raw = ['$ref' => $reference->ref];

        $raw = self::withOptional($raw, [
            'summary' => $reference->summary,
            'description' => $reference->description,
        ]);

        return [...$raw, ...$reference->extensions];
    }

    /**
     * Append every non-null candidate to the raw array under its key. Null
     * always means "absent" here: every explicit-null-capable keyword either
     * has a presence flag (default, const, additionalProperties) or round
     * trips through the extra bag instead of a typed property.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $candidates
     * @return array<string, mixed>
     */
    private static function withOptional(array $raw, array $candidates): array
    {
        foreach ($candidates as $key => $value) {
            if ($value !== null) {
                $raw[$key] = $value;
            }
        }

        return $raw;
    }
}
