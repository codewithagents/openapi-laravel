<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\MediaTypeNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OperationNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ParameterNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ReferenceNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\RequestBodyNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponseNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\ResponsesNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

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
    /**
     * The fixed HTTP methods the generator emits routes for. The OpenAPI 3.2
     * `query` method and `additionalOperations` are deliberately absent: those
     * constructs are dropped from the output (issue #102), so they never seed
     * a closure or claim a schema either.
     */
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    /**
     * Resolve a selection against a parsed document. Returns the closed schema
     * set, the kept operation keys, and the unmatched (unknown) tags/schemas.
     */
    public function resolve(OpenApiDocument $document, SubsetSelection $selection): ResolvedClosure
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

            foreach ($document->paths as $path => $pathItem) {
                foreach (self::HTTP_METHODS as $method) {
                    $operation = $pathItem->{$method} ?? null;
                    if (! $operation instanceof OperationNode) {
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
     * Tag-group attribution for the opt-in grouped data layout (issue #93):
     * which component schemas are owned by exactly one tag group, and which
     * group that is. The unit of ownership is the operation's tag GROUP (the
     * StudlyCaps first tag, via {@see TagGroups}), the same rule that names
     * its controller, so the data layout always mirrors the controller layout.
     *
     * A schema is attributed to a group when it is reachable from that group's
     * operations through the SAME transitive walk the subset closure uses
     * (properties, items, additionalProperties, allOf/oneOf/anyOf members,
     * discriminator mappings, and the union base <-> variant linkage), and
     * from NO other group's operations. A schema reachable from several
     * groups, or from none (an unreferenced component), is absent from the
     * returned map and stays at the flat root. A tag normalizing to the
     * reserved 'Support' group still counts as a referencing group (so its
     * schemas are not mis-attributed to another tag), but can never be the
     * owner: its sole-owned schemas stay at the root too.
     *
     * Determinism: groups are walked in sorted order, membership comes from an
     * order-independent visited-set walk, and the result is keyed by sorted
     * schema name, so the same spec always yields the same map.
     *
     * @return array<string, string> schema name => owning group
     */
    public function attributeByTag(OpenApiDocument $document): array
    {
        $schemas = $this->componentSchemas($document);
        $discriminators = new DiscriminatorRegistry($schemas);

        // Seed set per group. The empty-string key marks the reserved Support
        // pseudo-owner: it participates in multi-group detection but never owns.
        /** @var array<string, array<string, true>> $seedsByGroup */
        $seedsByGroup = [];
        foreach ($document->paths as $pathItem) {
            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem->{$method} ?? null;
                if (! $operation instanceof OperationNode) {
                    continue;
                }

                $group = TagGroups::forOperation($operation) ?? '';
                foreach ($this->operationSchemaSeeds($operation) as $seed) {
                    if (isset($schemas[$seed])) {
                        $seedsByGroup[$group][$seed] = true;
                    }
                }
            }
        }
        ksort($seedsByGroup);

        // Walk each group's transitive closure and record which groups reach
        // each schema.
        /** @var array<string, array<string, true>> $owners */
        $owners = [];
        foreach ($seedsByGroup as $group => $seeds) {
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

            foreach (array_keys($kept) as $name) {
                $owners[$name][(string) $group] = true;
            }
        }
        ksort($owners);

        $attribution = [];
        foreach ($owners as $name => $groups) {
            if (count($groups) !== 1) {
                continue;
            }

            $group = (string) array_key_first($groups);
            if ($group === '') {
                // Sole owner is the reserved Support pseudo-group: root.
                continue;
            }

            $attribution[$name] = $group;
        }

        return $attribution;
    }

    /**
     * The component-schema names a single schema depends on: every `$ref`
     * reachable through its structure, plus the discriminated-union base/variant
     * links. Returns bare component names (the closure walk dedupes/visits).
     *
     * @return list<string>
     */
    private function dependenciesOf(string $name, SchemaNode $schema, DiscriminatorRegistry $discriminators): array
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
    private function collectRefs(SchemaNode|ReferenceNode|null $node, array &$found): void
    {
        if ($node instanceof ReferenceNode) {
            $name = SchemaPointer::refName($node->pointer());
            if ($name !== null) {
                $found[$name] = true;
            }

            return;
        }

        if (! $node instanceof SchemaNode) {
            return;
        }

        // Object properties.
        foreach ($node->properties ?? [] as $property) {
            $this->collectRefs($property, $found);
        }

        // Array items (the recursion handles arrays of arrays).
        $this->collectRefs($node->items, $found);

        // additionalProperties value schema (typed map). A boolean value carries
        // no ref.
        $additional = $node->additionalProperties;
        if ($additional instanceof SchemaNode || $additional instanceof ReferenceNode) {
            $this->collectRefs($additional, $found);
        }

        // Composition members.
        foreach ([$node->allOf, $node->oneOf, $node->anyOf] as $members) {
            foreach ($members ?? [] as $member) {
                $this->collectRefs($member, $found);
            }
        }

        // discriminator.mapping targets. These are not always present as oneOf
        // members (a mapping can name a schema that is not listed), so they are
        // collected explicitly to keep the union resolvable.
        $discriminator = $node->discriminator;
        if ($discriminator !== null) {
            foreach ($discriminator->mapping ?? [] as $target) {
                $resolved = str_starts_with($target, '#/') ? SchemaPointer::refName($target) : ($target === '' ? null : $target);
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
    private function operationSchemaSeeds(OperationNode $operation): array
    {
        $found = [];

        $body = $operation->requestBody;
        if ($body instanceof RequestBodyNode) {
            foreach ($this->contentSchemas($body->content) as $schema) {
                $this->collectRefs($schema, $found);
            }
        }

        $responses = $operation->responses;
        if ($responses instanceof ResponsesNode) {
            foreach ($responses->responses as $response) {
                if ($response instanceof ResponseNode) {
                    foreach ($this->contentSchemas($response->content) as $schema) {
                        $this->collectRefs($schema, $found);
                    }
                }
            }
        }

        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof ParameterNode && $parameter->schema !== null) {
                $this->collectRefs($parameter->schema, $found);
            }
        }

        return array_keys($found);
    }

    /**
     * The schema of each media type in a content map (request body / response).
     *
     * @param  array<string, MediaTypeNode>  $content
     * @return list<SchemaNode|ReferenceNode>
     */
    private function contentSchemas(array $content): array
    {
        $result = [];
        foreach ($content as $media) {
            if ($media->schema !== null) {
                $result[] = $media->schema;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function operationTags(OperationNode $operation): array
    {
        $result = [];
        foreach ($operation->tags as $tag) {
            if (trim($tag) !== '') {
                // Trim the spec's tag before matching: the selection names are
                // already trimmed (SubsetSelection), so a whitespace-padded tag
                // in the document still matches a clean --only-tags value.
                $result[] = trim($tag);
            }
        }

        return $result;
    }

    /**
     * @return array<string, SchemaNode>
     */
    private function componentSchemas(OpenApiDocument $document): array
    {
        $components = $document->components;
        if ($components === null) {
            return [];
        }

        $result = [];
        foreach ($components->schemas as $name => $schema) {
            if ($schema instanceof SchemaNode) {
                $result[(string) $name] = $schema;
            }
        }

        return $result;
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
