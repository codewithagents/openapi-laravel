<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

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

/**
 * Resolves a {@see SubsetSelection} into the concrete set of component schemas
 * and operations the generator must emit so the output stays self-consistent
 * (issue #44).
 *
 * The hard requirement is the TRANSITIVE DEPENDENCY CLOSURE: selecting a schema
 * (directly via --only-schemas, or indirectly via an --only-tags operation that
 * references it) must drag in every schema reachable from it, or the generated
 * code would carry a dangling `$ref`, a missing union variant, or a broken
 * `extends`. The walk follows every `$ref` reachable from a selected schema:
 *
 *  - object `properties` values,
 *  - array `items` (recursively, including arrays of arrays),
 *  - `additionalProperties` value schemas (typed maps),
 *  - `allOf` / `oneOf` / `anyOf` members,
 *  - `discriminator.mapping` targets, and
 *  - the abstract-base <-> variant relationship of a discriminated union: a
 *    selected base pulls in all its variants AND a selected variant pulls in its
 *    base and that base's other variants, so the emitted morphable union still
 *    resolves (the base's match() arms all point at present classes, and each
 *    variant's `extends Base` resolves).
 *
 * Recursion terminates on a visited-set: a self-referential schema (a tree node
 * pointing back at its own type) or a ref cycle (A -> B -> A) is followed once.
 *
 * Determinism: the returned schema set is sorted, and the operation set is
 * keyed by "METHOD path" and sorted, so a subset run is byte-stable regardless
 * of selection order. The resolver reports unknown tags and schemas (a flag
 * that matched nothing) so the caller can fail loudly instead of silently
 * emitting an empty slice.
 *
 * @internal
 */
final readonly class SchemaClosure
{
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    /**
     * Resolve a selection against a parsed document. Returns the closed schema
     * set, the kept operation keys, and the unmatched (unknown) tags/schemas.
     */
    public function resolve(OpenApi $document, SubsetSelection $selection): ResolvedClosure
    {
        $schemas = $this->componentSchemas($document);
        $discriminators = new DiscriminatorRegistry($schemas);

        // Seed the closure with the directly-named component schemas, recording
        // any name that does not exist so the caller can report it. The walk is
        // run later over the whole seed set at once.
        $seeds = [];
        $unknownSchemas = [];
        foreach ($selection->schemas as $name) {
            if (isset($schemas[$name])) {
                $seeds[$name] = true;
            } else {
                $unknownSchemas[] = $name;
            }
        }

        // Tag selection: keep every operation that carries a selected tag, seed
        // the closure with each schema those operations reference, and remember
        // which path+method pairs survived so the server scaffold can be
        // restricted to them. A tag that matches no operation is reported.
        $operationKeys = [];
        $unknownTags = [];
        if ($selection->tags !== []) {
            $wanted = array_fill_keys($selection->tags, false);

            foreach ($this->pathItems($document) as $path => $pathItem) {
                foreach (self::HTTP_METHODS as $method) {
                    $operation = $pathItem->{$method} ?? null;
                    if (! $operation instanceof Operation) {
                        continue;
                    }

                    $hit = $this->operationTags($operation);
                    $selectedHere = array_values(array_filter($hit, static fn (string $t): bool => array_key_exists($t, $wanted)));
                    if ($selectedHere === []) {
                        continue;
                    }

                    foreach ($selectedHere as $tag) {
                        $wanted[$tag] = true;
                    }
                    $operationKeys[strtoupper($method).' '.$path] = true;

                    foreach ($this->operationSchemaSeeds($operation) as $seed) {
                        if (isset($schemas[$seed])) {
                            $seeds[$seed] = true;
                        }
                    }
                }
            }

            // A selected tag that matched no operation is unknown: report it so
            // the caller can fail loudly rather than silently emit nothing for it.
            foreach ($wanted as $tag => $found) {
                if (! $found) {
                    $unknownTags[] = (string) $tag;
                }
            }
        }

        // Walk the transitive closure from every seed. The visited set IS the
        // kept schema set: every name reached is emitted, nothing else.
        $kept = [];
        $stack = array_keys($seeds);
        while ($stack !== []) {
            $name = array_pop($stack);
            if (isset($kept[$name]) || ! isset($schemas[$name])) {
                continue;
            }
            $kept[$name] = true;

            foreach ($this->dependenciesOf($name, $schemas[$name], $discriminators) as $dependency) {
                if (! isset($kept[$dependency])) {
                    $stack[] = $dependency;
                }
            }
        }

        $keptNames = array_keys($kept);
        sort($keptNames);

        $keys = array_keys($operationKeys);
        sort($keys);

        return new ResolvedClosure(
            $keptNames,
            $keys,
            $this->cleanList($unknownTags),
            $this->cleanList($unknownSchemas),
        );
    }

    /**
     * The component-schema names a single schema depends on: every `$ref`
     * reachable through its structure, plus the discriminated-union base/variant
     * links. Returns bare component names (the closure walk dedupes/visits).
     *
     * @return list<string>
     */
    private function dependenciesOf(string $name, Schema $schema, DiscriminatorRegistry $discriminators): array
    {
        $found = [];
        $this->collectRefs($schema, $found);

        // Discriminated-union linkage in the variant -> base direction. A selected
        // base already drags in every variant through collectRefs (it walks the
        // base's oneOf/anyOf members AND its discriminator mapping targets, which
        // together ARE the variant set). The reverse is NOT structural: a variant
        // schema carries no ref back to its base, yet it `extends` the base and
        // the base's morph() arms name every sibling, so selecting one variant
        // must pull in the base and all its siblings or the generated union would
        // not resolve. That is the link we add here.
        if ($discriminators->isVariant($name)) {
            $base = $discriminators->baseOf($name);
            if ($base !== null) {
                $found[$base] = true;
                foreach ($discriminators->variants($base) as $variant) {
                    $found[$variant] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Recursively gather every component-schema `$ref` reachable from a schema
     * node WITHOUT crossing into a referenced component (a `$ref` records the
     * name and stops: the referenced schema is walked separately by the closure
     * loop). Inline subschemas (properties, items, additionalProperties,
     * composition members) ARE descended into, since their refs belong to this
     * schema's dependency set.
     *
     * @param  array<string, true>  $found  accumulator, name => true
     */
    private function collectRefs(Schema|Reference|null $node, array &$found): void
    {
        if ($node instanceof Reference) {
            $name = $this->refName($node->getReference());
            if ($name !== null) {
                $found[$name] = true;
            }

            return;
        }

        if (! $node instanceof Schema) {
            return;
        }

        // Object properties.
        $properties = $node->properties;
        if (is_array($properties)) {
            foreach ($properties as $property) {
                $this->collectRefs($property instanceof Schema || $property instanceof Reference ? $property : null, $found);
            }
        }

        // Array items (the recursion handles arrays of arrays).
        $items = $node->items;
        if ($items instanceof Schema || $items instanceof Reference) {
            $this->collectRefs($items, $found);
        }

        // additionalProperties value schema (typed map). A boolean value carries
        // no ref.
        $additional = $node->additionalProperties;
        if ($additional instanceof Schema || $additional instanceof Reference) {
            $this->collectRefs($additional, $found);
        }

        // Composition members.
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            $members = $node->{$keyword};
            if (is_array($members)) {
                foreach ($members as $member) {
                    $this->collectRefs($member instanceof Schema || $member instanceof Reference ? $member : null, $found);
                }
            }
        }

        // discriminator.mapping targets. These are not always present as oneOf
        // members (a mapping can name a schema that is not listed), so they are
        // collected explicitly to keep the union resolvable.
        $discriminator = $node->discriminator;
        if ($discriminator !== null && is_array($discriminator->mapping)) {
            foreach ($discriminator->mapping as $target) {
                if (! is_string($target)) {
                    continue;
                }
                $resolved = str_starts_with($target, '#/') ? $this->refName($target) : ($target === '' ? null : $target);
                if ($resolved !== null) {
                    $found[$resolved] = true;
                }
            }
        }
    }

    /**
     * The component-schema names referenced directly by an operation's request
     * body, responses, and parameters (one hop). The closure walk then takes
     * each of these to its own transitive closure.
     *
     * @return list<string>
     */
    private function operationSchemaSeeds(Operation $operation): array
    {
        $found = [];

        $body = $operation->requestBody;
        if ($body instanceof RequestBody) {
            foreach ($this->contentSchemas($body->content) as $schema) {
                $this->collectRefs($schema, $found);
            }
        }

        $responses = $operation->responses;
        if ($responses instanceof Responses) {
            foreach ($responses->getResponses() as $response) {
                if ($response instanceof Response) {
                    foreach ($this->contentSchemas($response->content) as $schema) {
                        $this->collectRefs($schema, $found);
                    }
                }
            }
        }

        $parameters = $operation->parameters;
        if (is_array($parameters)) {
            foreach ($parameters as $parameter) {
                if ($parameter instanceof Parameter && ($parameter->schema instanceof Schema || $parameter->schema instanceof Reference)) {
                    $this->collectRefs($parameter->schema, $found);
                }
            }
        }

        return array_keys($found);
    }

    /**
     * The schema of each media type in a content map (request body / response).
     *
     * @return list<Schema|Reference>
     */
    private function contentSchemas(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $result = [];
        foreach ($content as $media) {
            if ($media instanceof MediaType && ($media->schema instanceof Schema || $media->schema instanceof Reference)) {
                $result[] = $media->schema;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function operationTags(Operation $operation): array
    {
        $tags = $operation->tags;
        if (! is_array($tags)) {
            return [];
        }

        $result = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                // Trim the spec's tag before matching: the selection names are
                // already trimmed (SubsetSelection), so a whitespace-padded tag
                // in the document still matches a clean --only-tags value.
                $result[] = trim($tag);
            }
        }

        return $result;
    }

    /**
     * @return array<string, Schema>
     */
    private function componentSchemas(OpenApi $document): array
    {
        $components = $document->components;
        if ($components === null) {
            return [];
        }

        $result = [];
        foreach ($components->schemas as $name => $schema) {
            if ($schema instanceof Schema) {
                $result[(string) $name] = $schema;
            }
        }

        return $result;
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
     * @param  list<string>  $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }
}
