<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads an OpenAPI 3.0/3.1 document from disk and returns the typed internal
 * {@see OpenApiDocument} graph hydrated by {@see OpenApiReader} (3.2 is
 * accepted best-effort with warnings, anything else is rejected, issue #103).
 * Format is detected from the file extension, falling back to sniffing the
 * first meaningful byte. All underlying parser failures are normalised to
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

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(?int $maxBytes = null)
    {
        $this->maxBytes = $maxBytes ?? self::DEFAULT_MAX_BYTES;
    }

    /**
     * Non-fatal diagnostics from the most recent parseFileToDocument() call
     * (issue #103): a 3.2 document is accepted best-effort, and every 3.2-only
     * construct the generator would silently drop is reported here, one
     * warning per occurrence. Empty for a fully supported 3.0.x/3.1.x
     * document.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Parse a spec file into the typed document graph: existence check, size
     * guard, MemoryGuard around the decode and hydration, format sniffing,
     * then {@see OpenApiReader} hydration. The reader performs the schema
     * normalization rewrites (#20, #32, #33, #82) and the structural/version
     * gates (#103) itself, and its warnings travel on the document; they are
     * mirrored into {@see warnings()} for the command-level reporting surface.
     */
    public function parseFileToDocument(string $path): OpenApiDocument
    {
        $this->warnings = [];

        if (! is_file($path) || ! is_readable($path)) {
            throw new ParseException("OpenAPI spec not found or not readable: {$path}");
        }

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
            $contents = (string) file_get_contents($absolute);

            $data = $this->isYaml($absolute)
                ? Yaml::parse($contents)
                : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            $document = (new OpenApiReader)->read($data, $path);
        } catch (ParseException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ParseException("Failed to parse OpenAPI spec ({$path}): {$e->getMessage()}", 0, $e);
        } finally {
            MemoryGuard::disarm();
        }

        $this->warnings = $document->warnings;

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
