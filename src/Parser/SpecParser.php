<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Parser;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\json\JsonPointer;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads an OpenAPI 3.0/3.1 document from disk and returns the cebe object
 * model (3.2 is accepted best-effort with warnings, anything else is rejected,
 * issue #103). Format is detected from the file extension, falling back to sniffing
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

    /**
     * The canonical statement of the supported version matrix (issue #103)
     * lives on {@see OpenApiReader} since Task 2 of issue #104; these aliases
     * keep the cebe path reading the same single source of truth until that
     * path is deleted (Task 7).
     */
    private const ISSUE_102_URL = OpenApiReader::ISSUE_102_URL;

    private const SUPPORTED_MATRIX = OpenApiReader::SUPPORTED_MATRIX;

    private readonly int $maxBytes;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * `$useNewReader` is the issue #104 Task 3 comparison toggle: when set,
     * {@see parseFile} routes the decoded data through {@see OpenApiReader}
     * and {@see SpecArraySerializer} before constructing the cebe object
     * model, so the corpus acceptance gate can compare the generated output
     * of both paths byte-for-byte with the emitter unchanged. Throwaway
     * scaffolding, deleted with the cebe path in Task 7; nothing outside the
     * comparison gate may set it.
     */
    public function __construct(?int $maxBytes = null, private readonly bool $useNewReader = false)
    {
        $this->maxBytes = $maxBytes ?? self::DEFAULT_MAX_BYTES;
    }

    /**
     * Non-fatal diagnostics from the most recent parseFile() call (issue #103):
     * a 3.2 document is accepted best-effort, and every 3.2-only construct the
     * generator would silently drop is reported here, one warning per
     * occurrence. Empty for a fully supported 3.0.x/3.1.x document.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function parseFile(string $path, bool $validate = false): OpenApi
    {
        $this->warnings = [];

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
            [$document, $raw] = $this->readDocument($absolute);
        } catch (Throwable $e) {
            throw new ParseException("Failed to parse OpenAPI spec ({$path}): {$e->getMessage()}", 0, $e);
        } finally {
            MemoryGuard::disarm();
        }

        $this->assertOpenApiDocument($document, $path, $raw);

        if ($validate && ! $document->validate()) {
            $errors = implode('; ', $document->getErrors());

            throw new ParseException("OpenAPI spec failed validation ({$path}): {$errors}");
        }

        return $document;
    }

    /**
     * The new-reader path (issue #104, Task 2): same file handling as
     * {@see parseFile} (existence check, size guard, MemoryGuard around the
     * decode and hydration, format sniffing), but the decoded data is handed
     * to {@see OpenApiReader} and comes back as the typed internal
     * {@see OpenApiDocument} graph instead of the cebe object model. The
     * reader folds the SchemaNormalizer rewrites and the structural/version
     * gates itself, and its warnings travel on the document; they are
     * mirrored into {@see warnings()} so both paths report identically.
     *
     * Additive for now: nothing in src/ consumes this yet. Task 3 wires the
     * corpus comparison gate against it, Tasks 4-5 migrate the callers, and
     * Task 7 deletes the cebe path.
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
     * Returns the cebe object model TOGETHER with the normalised raw array, so
     * the version gate (#103) can scan for raw keys the object model does not
     * carry (the 3.2-only constructs cebe silently ignores).
     *
     * @return array{0: OpenApi, 1: array<array-key, mixed>}
     *
     * @throws \Symfony\Component\Yaml\Exception\ParseException when the YAML is malformed
     * @throws \JsonException when the JSON is malformed
     * @throws TypeErrorException when the document data is ill-typed
     * @throws UnresolvableReferenceException when the base URI is not absolute
     */
    private function readDocument(string $absolute): array
    {
        $contents = (string) file_get_contents($absolute);

        $data = $this->isYaml($absolute)
            ? Yaml::parse($contents)
            : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if ($this->useNewReader) {
            // Issue #104 Task 3 comparison path: hydrate the typed graph and
            // serialize it back to the raw array form. The reader performs
            // the SchemaNormalizer rewrites itself, so the cebe model below
            // is built from the round-tripped data; any information the
            // reader loses shows up as a byte diff in the corpus gate.
            $data = SpecArraySerializer::toArray((new OpenApiReader)->read($data, $absolute));
        } else {
            $data = SchemaNormalizer::normalize($data);
        }

        $raw = is_array($data) ? $data : [];

        $document = new OpenApi($raw);

        // Establish the same base-URI reference context cebe's file readers set,
        // so any later $ref handling keeps the document's path. We do NOT resolve
        // references here (the emitter resolves the ones it needs, with cycle
        // protection), matching the previous `resolveReferences: false` call.
        $context = new ReferenceContext($document, $absolute);
        $document->setReferenceContext($context);
        $document->setDocumentContext($document, new JsonPointer(''));

        return [$document, $raw];
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
     * Lightweight structural check (A-1) plus the exact version gate (#103).
     * cebe is invoked with validate:false so a Swagger 2.0, non-OpenAPI, or
     * empty document otherwise parses into an OpenApi object full of nulls and
     * the generator silently produces nothing. We require an `openapi` version
     * string and an info object, naming what we actually found, rather than
     * switching to full cebe validation (which would risk rejecting
     * currently-working corpus specs).
     *
     * The version is gated on the exact minor, not a `3.` prefix: 3.0.x and
     * 3.1.x are fully supported; 3.2.x is accepted best-effort with a loud
     * warning plus one warning per 3.2-only construct the generator drops
     * (`query` operations, `additionalOperations`, `itemSchema` media types,
     * scanned in the raw array because cebe silently ignores them); anything
     * else is rejected with an error naming the supported matrix.
     *
     * @param  array<array-key, mixed>  $raw  the normalised raw document data
     */
    private function assertOpenApiDocument(OpenApi $document, string $path, array $raw): void
    {
        $version = $document->openapi;

        if (! is_string($version) || $version === '') {
            throw new ParseException("Not an OpenAPI 3.x document ({$path}): missing 'openapi' version string. Swagger 2.0 and other formats are not supported. ".self::SUPPORTED_MATRIX);
        }

        if (preg_match('/^3\.(\d+)(?:[.\-+]|$)/', $version, $matches) !== 1 || ! in_array((int) $matches[1], [0, 1, 2], true)) {
            throw new ParseException("Unsupported OpenAPI version '{$version}' ({$path}). ".self::SUPPORTED_MATRIX);
        }

        if ($document->info === null) {
            throw new ParseException("Not a valid OpenAPI document ({$path}): missing required 'info' object.");
        }

        if ((int) $matches[1] === 2) {
            $this->warnings[] = sprintf(
                "OpenAPI 3.2 is not fully supported yet: '%s' (%s) is accepted best-effort, and 3.2-only constructs are dropped from the generated output. Full 3.2 support is tracked in issue #102 (%s). %s",
                $version,
                $path,
                self::ISSUE_102_URL,
                self::SUPPORTED_MATRIX,
            );
            $this->warnings = [...$this->warnings, ...$this->scanDropped32Constructs($raw)];
        }
    }

    /**
     * One warning per 3.2-only construct occurrence the generator silently
     * drops (issue #103). The scan reads raw keys only: cebe's object model
     * ignores unknown Path Item and Media Type members, so the raw array is
     * the only place these constructs are still visible.
     *
     * @param  array<array-key, mixed>  $raw
     * @return list<string>
     */
    private function scanDropped32Constructs(array $raw): array
    {
        $warnings = [];

        // The 3.2 Path Item Object gains `query` (a QUERY method operation)
        // and `additionalOperations` (custom-method operations). Both live
        // directly on the path items under `paths` (and 3.2 `webhooks`).
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
     * entry carries an `itemSchema` key. The walk mirrors SchemaNormalizer's
     * plain recursion over the already size-guarded raw document.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $warnings
     */
    private function scanItemSchemas(array $node, string $trail, array &$warnings): void
    {
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

            $this->scanItemSchemas($value, $here, $warnings);
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
