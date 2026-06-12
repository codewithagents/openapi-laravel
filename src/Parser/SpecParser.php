<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\json\JsonPointer;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads an OpenAPI 3.0/3.1 document from disk and returns the cebe object
 * model. Format is detected from the file extension, falling back to sniffing
 * the first meaningful byte. All underlying parser failures are normalised to
 * {@see ParseException}.
 *
 * References are intentionally left unresolved. Eager inline resolution of a
 * large spec (thousands of internal $refs) is pathologically slow, and the
 * generator wants component $refs mapped to class names anyway, not inlined.
 * The emitter resolves the $refs it needs, with cycle protection.
 *
 * @internal
 */
final class SpecParser
{
    /**
     * Default upper bound on the raw spec file size, in bytes. The OpenAPI spec
     * is untrusted input handed to a YAML parser that expands anchors and aliases
     * before we see the data (a classic "billion laughs" / alias-bomb vector that
     * the vendored library cannot disable). A cheap pre-parse size guard caps the
     * blast radius. The default sits well above the largest real-world corpus
     * spec (~11 MB) so legitimate specs are never rejected. Override via the
     * constructor (wired to GeneratorOptions/config) when you genuinely need more.
     */
    public const DEFAULT_MAX_BYTES = 25_165_824; // 24 MiB

    private readonly int $maxBytes;

    public function __construct(?int $maxBytes = null)
    {
        $this->maxBytes = $maxBytes ?? self::DEFAULT_MAX_BYTES;
    }

    public function parseFile(string $path, bool $validate = false): OpenApi
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ParseException("OpenAPI spec not found or not readable: {$path}");
        }

        // cebe requires an absolute path to establish a base URI for $refs.
        $absolute = realpath($path);

        if ($absolute === false) {
            throw new ParseException("Unable to resolve real path for spec: {$path}");
        }

        $this->guardSize($absolute);

        // A real out-of-memory fatal inside the YAML parser is a non-catchable
        // PHP error, so the surrounding try/catch cannot turn it into a clean
        // ParseException. Arm a shutdown handler around the parse step instead so
        // an OOM prints an actionable message rather than a raw fatal + trace
        // (issue #17). The guard is disarmed as soon as the parse step returns.
        MemoryGuard::arm($absolute, $this->maxBytes);

        try {
            $document = $this->readDocument($absolute);
        } catch (Throwable $e) {
            throw new ParseException("Failed to parse OpenAPI spec ({$path}): {$e->getMessage()}", 0, $e);
        } finally {
            MemoryGuard::disarm();
        }

        $this->assertOpenApiDocument($document, $path);

        if ($validate && ! $document->validate()) {
            $errors = implode('; ', $document->getErrors());

            throw new ParseException("OpenAPI spec failed validation ({$path}): {$errors}");
        }

        return $document;
    }

    /**
     * Decode the raw spec, normalise it, then hand it to the cebe object model.
     *
     * This mirrors cebe's own `Reader::readFrom{Yaml,Json}File` (decode the file,
     * `new OpenApi($data)`, attach a non-resolving ReferenceContext) with one
     * extra step: {@see SchemaNormalizer} rewrites boolean `items` before cebe
     * sees it, because cebe cannot instantiate a Schema from a boolean (#20).
     * References are left unresolved, exactly as before.
     *
     * Every checked exception below is caught by the sole caller (parseFile)
     * and re-thrown as a ParseException, so these are propagated, never
     * swallowed: a YAML syntax error surfaces as ParseException, malformed
     * JSON as ParseException, an ill-typed document as ParseException, and a
     * non-absolute base URI as ParseException, all keeping the original as the
     * chained previous exception.
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException when the YAML is malformed
     * @throws \JsonException when the JSON is malformed
     * @throws TypeErrorException when the document data is ill-typed
     * @throws UnresolvableReferenceException when the base URI is not absolute
     */
    private function readDocument(string $absolute): OpenApi
    {
        $contents = (string) file_get_contents($absolute);

        $data = $this->isYaml($absolute)
            ? Yaml::parse($contents)
            : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $data = SchemaNormalizer::normalize($data);

        $document = new OpenApi(is_array($data) ? $data : []);

        // Establish the same base-URI reference context cebe's file readers set,
        // so any later $ref handling keeps the document's path. We do NOT resolve
        // references here (the emitter resolves the ones it needs, with cycle
        // protection), matching the previous `resolveReferences: false` call.
        $context = new ReferenceContext($document, $absolute);
        $document->setReferenceContext($context);
        $document->setDocumentContext($document, new JsonPointer(''));

        return $document;
    }

    /**
     * Cheap pre-parse input-size guard (B-1). Rejects oversized input before it
     * reaches the YAML parser, bounding the cost of alias/anchor expansion.
     */
    private function guardSize(string $absolute): void
    {
        $size = filesize($absolute);

        if ($size !== false && $size > $this->maxBytes) {
            throw new ParseException(sprintf(
                'OpenAPI spec is too large (%d bytes, limit %d bytes). Raise --max-bytes only for trusted '
                .'specs, and note that a larger spec needs a proportionally larger PHP memory_limit to parse '
                .'(it can otherwise exhaust memory mid-parse). Or run the generator under OS-level resource limits.',
                $size,
                $this->maxBytes,
            ));
        }
    }

    /**
     * Lightweight structural check (A-1). cebe is invoked with validate:false so
     * a Swagger 2.0, non-OpenAPI, or empty document otherwise parses into an
     * OpenApi object full of nulls and the generator silently produces nothing.
     * We require an OpenAPI 3.x version string and an info object, naming what we
     * actually found, rather than switching to full cebe validation (which would
     * risk rejecting currently-working corpus specs).
     */
    private function assertOpenApiDocument(OpenApi $document, string $path): void
    {
        $version = $document->openapi;

        if (! is_string($version) || $version === '') {
            throw new ParseException("Not an OpenAPI 3.x document ({$path}): missing 'openapi' version string. Swagger 2.0 and other formats are not supported.");
        }

        if (! str_starts_with($version, '3.')) {
            throw new ParseException("Unsupported OpenAPI version '{$version}' ({$path}): only 3.x documents are supported.");
        }

        if ($document->info === null) {
            throw new ParseException("Not a valid OpenAPI document ({$path}): missing required 'info' object.");
        }
    }

    private function isYaml(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'yaml' || $extension === 'yml') {
            return true;
        }

        if ($extension === 'json') {
            return false;
        }

        // Unknown extension: sniff the first non-whitespace byte. JSON documents
        // begin with '{' or '['; anything else is treated as YAML.
        $contents = (string) file_get_contents($path);
        $trimmed = ltrim($contents);

        return $trimmed === '' || ! in_array($trimmed[0], ['{', '['], true);
    }
}
