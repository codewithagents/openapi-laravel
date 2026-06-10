<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser;

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
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

        try {
            $document = $this->isYaml($absolute)
                ? Reader::readFromYamlFile($absolute, OpenApi::class, false)
                : Reader::readFromJsonFile($absolute, OpenApi::class, false);
        } catch (Throwable $e) {
            throw new ParseException("Failed to parse OpenAPI spec ({$path}): {$e->getMessage()}", 0, $e);
        }

        $this->assertOpenApiDocument($document, $path);

        if ($validate && ! $document->validate()) {
            $errors = implode('; ', $document->getErrors());

            throw new ParseException("OpenAPI spec failed validation ({$path}): {$errors}");
        }

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
                'OpenAPI spec is too large (%d bytes, limit %d bytes). Raise the limit only for trusted specs, or run the generator under OS-level resource limits.',
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
