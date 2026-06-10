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

        try {
            $document = $this->isYaml($absolute)
                ? Reader::readFromYamlFile($absolute, OpenApi::class, false)
                : Reader::readFromJsonFile($absolute, OpenApi::class, false);
        } catch (Throwable $e) {
            throw new ParseException("Failed to parse OpenAPI spec ({$path}): {$e->getMessage()}", 0, $e);
        }

        if ($validate && ! $document->validate()) {
            $errors = implode('; ', $document->getErrors());

            throw new ParseException("OpenAPI spec failed validation ({$path}): {$errors}");
        }

        return $document;
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
