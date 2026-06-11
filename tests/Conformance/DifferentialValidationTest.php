<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;
use CodeWithAgents\OpenApiLaravel\Tests\Conformance\ConstraintCatalog;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/*
 * Differential validation oracle (GitHub issue #23).
 *
 * For each constraint in the catalog this generates the Data class from a
 * minimal OpenAPI schema, then runs every VALID payload (spec says: accept) and
 * every INVALID payload (spec says: reject) through the generated rules() with
 * the real Laravel validator. The oracle hook is exactly the round-trip test's:
 * GeneratedClassData::validate() throws ValidationException when rejected and
 * returns normally when accepted.
 *
 * A valid payload that is REJECTED, or an invalid payload that is ACCEPTED, is a
 * differential mismatch (a candidate bug). Mismatches are NEVER silenced: they
 * are collected, printed, and written to DIFFERENTIAL_FINDINGS.md, and the suite
 * fails listing them. This file is a discovery oracle, not a green-suite target.
 *
 * The validator needs a booted Laravel container (validate() resolves a factory),
 * so this test opts into the Testbench TestCase explicitly (the Pest config only
 * binds it under tests/Feature).
 */
uses(TestCase::class);

/**
 * Generate the classes for one schema-set and load them into the running
 * process. Each construct gets a fresh namespace so classes never collide
 * across catalog entries.
 *
 * @param  array<string, mixed>  $schemas
 */
function differentialGenerate(string $namespace, array $schemas): void
{
    /** @var string|null $dir */
    static $dir = null;
    if ($dir === null) {
        $dir = sys_get_temp_dir().'/oal_differential_'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Differential', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    // Run the spec through the same pre-parse normalisation the real pipeline
    // applies (SpecParser does this before cebe). This is what coerces the
    // string-typed constraints (#32) and string `nullable` (#33) so the oracle
    // proves the coerced runtime behaviour, not the raw-string behaviour.
    $decoded = json_decode((string) json_encode($document), true);
    $normalized = SchemaNormalizer::normalize($decoded);

    $spec = Reader::readFromJson((string) json_encode($normalized), OpenApi::class);
    $files = (new ModelGenerator(new GeneratorOptions($namespace)))->generate($spec);

    // A discriminated-union variant `extends <Base>Data`, so the abstract base
    // must be declared before the variant is required. Load abstract bases first;
    // the rest keep their (deterministic) order.
    $ordered = [];
    $rest = [];
    foreach ($files as $name => $file) {
        if (str_contains($file->code, 'abstract class ')) {
            $ordered[$name] = $file;
        } else {
            $rest[$name] = $file;
        }
    }
    $ordered += $rest;

    foreach ($ordered as $file) {
        $path = $dir.'/'.str_replace('\\', '_', $namespace).'_'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }
}

/**
 * Run one payload through a generated class's validate(). Returns 'accept' when
 * validation passes and 'reject' when it throws ValidationException. Any other
 * throwable is surfaced as an 'error:<message>' outcome so generator crashes are
 * not mistaken for a clean accept/reject.
 *
 * @param  class-string  $class
 * @param  array<string, mixed>  $payload
 */
function differentialOutcome(string $class, array $payload): string
{
    try {
        /** @var callable $validator */
        $validator = [$class, 'validate'];
        $validator($payload);

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.$e->getMessage();
    }
}

/**
 * Documented limitations the oracle is expected to surface, each tracked by a
 * GitHub issue. A mismatch matching one of these (same construct and direction)
 * is reported but does NOT fail the suite. A known gap that no longer reproduces
 * fails the suite so its entry is removed once the limitation is fixed. Any
 * mismatch not listed here is new drift and always fails. This is the ratchet
 * that keeps the oracle green while staying honest about what is unenforced.
 *
 * @return list<array{construct: string, expected: string, actual: string, issue: string, reason: string}>
 */
function differentialKnownGaps(): array
{
    return [
        ['construct' => 'ObjAddlPropsFalse', 'expected' => 'reject', 'actual' => 'accept', 'issue' => '#30', 'reason' => 'additionalProperties: false is not enforced; unknown keys are accepted.'],
        ['construct' => 'PetHolder', 'expected' => 'reject', 'actual' => 'accept', 'issue' => '#31', 'reason' => 'Undiscriminated object-union is presence-only pending discriminator support: any object is accepted, no variant is enforced (1.0.0 hydration work). The interim fix traded variant enforcement for not false-rejecting valid variants.'],
    ];
}

function differentialGapKey(string $construct, string $expected, string $actual): string
{
    return $construct.'|'.$expected.'|'.$actual;
}

/**
 * @var list<array{construct: string, group: string, label: string, payload: array<array-key, mixed>, expected: string, actual: string, violates: string}>
 */
$mismatches = [];

it('differentially validates every catalog constraint against the generated rules', function () use (&$mismatches) {
    $namespace = 'Differential\\Models';

    // Assemble one document holding every catalog schema plus the union schemas,
    // generated once into a shared namespace.
    $schemas = [];
    foreach (ConstraintCatalog::cases() as $case) {
        $schemas[$case->construct] = $case->schema;
    }
    foreach (ConstraintCatalog::unionCases() as $union) {
        foreach ($union['schemas'] as $name => $schema) {
            $schemas[$name] = $schema;
        }
    }

    differentialGenerate($namespace, $schemas);

    $record = function (string $construct, string $group, string $label, array $payload, string $expected, string $actual, string $violates) use (&$mismatches): void {
        $mismatches[] = [
            'construct' => $construct,
            'group' => $group,
            'label' => $label,
            'payload' => $payload,
            'expected' => $expected,
            'actual' => $actual,
            'violates' => $violates,
        ];
    };

    foreach (ConstraintCatalog::cases() as $case) {
        $class = $namespace.'\\'.$case->construct.'Data';
        if (! class_exists($class)) {
            $mismatches[] = [
                'construct' => $case->construct,
                'group' => $case->group,
                'label' => 'class not generated',
                'payload' => [],
                'expected' => 'class exists',
                'actual' => 'missing',
                'violates' => '(generation)',
            ];

            continue;
        }

        foreach ($case->valid as $entry) {
            $actual = differentialOutcome($class, $entry['payload']);
            if ($actual !== 'accept') {
                $record($case->construct, $case->group, $entry['label'], $entry['payload'], 'accept', $actual, '(valid payload)');
            }
        }
        foreach ($case->invalid as $entry) {
            $actual = differentialOutcome($class, $entry['payload']);
            if ($actual !== 'reject') {
                $record($case->construct, $case->group, $entry['label'], $entry['payload'], 'reject', $actual, $entry['violates']);
            }
        }
    }

    foreach (ConstraintCatalog::unionCases() as $union) {
        $class = $namespace.'\\'.$union['root'].'Data';
        if (! class_exists($class)) {
            $mismatches[] = [
                'construct' => $union['construct'],
                'group' => 'union',
                'label' => 'class not generated',
                'payload' => [],
                'expected' => 'class exists',
                'actual' => 'missing',
                'violates' => '(generation)',
            ];

            continue;
        }
        foreach ($union['valid'] as $entry) {
            $actual = differentialOutcome($class, $entry['payload']);
            if ($actual !== 'accept') {
                $record($union['construct'], 'union', $entry['label'], $entry['payload'], 'accept', $actual, '(valid payload)');
            }
        }
        foreach ($union['invalid'] as $entry) {
            $actual = differentialOutcome($class, $entry['payload']);
            if ($actual !== 'reject') {
                $record($union['construct'], 'union', $entry['label'], $entry['payload'], 'reject', $actual, $entry['violates']);
            }
        }
    }

    // Always write the findings report (empty or not) so the artifact is fresh.
    writeDifferentialReport($mismatches);

    // Partition mismatches against the documented known-gap list. Unlisted
    // mismatches are new drift and fail; listed ones are tolerated. A known gap
    // that did not reproduce was fixed, so it must be removed from the list.
    $gaps = [];
    foreach (differentialKnownGaps() as $gap) {
        $gaps[differentialGapKey($gap['construct'], $gap['expected'], $gap['actual'])] = $gap;
    }

    $unexpected = [];
    $seenGapKeys = [];
    foreach ($mismatches as $mismatch) {
        $key = differentialGapKey($mismatch['construct'], $mismatch['expected'], $mismatch['actual']);
        if (isset($gaps[$key])) {
            $seenGapKeys[$key] = true;

            continue;
        }
        $unexpected[] = $mismatch;
    }

    $resolved = [];
    foreach ($gaps as $key => $gap) {
        if (! isset($seenGapKeys[$key])) {
            $resolved[] = $gap['construct'].' ('.$gap['issue'].')';
        }
    }

    if ($unexpected !== []) {
        fwrite(STDERR, "\n".renderDifferentialReport($unexpected)."\n");
    }

    expect($unexpected)->toBe([], 'Differential oracle found '.count($unexpected).' UNEXPECTED constraint mismatch(es), i.e. new drift; see tests/Conformance/DIFFERENTIAL_FINDINGS.md');
    expect($resolved)->toBe([], 'Known gap(s) no longer reproduce and were likely fixed: '.implode(', ', $resolved).'. Remove them from differentialKnownGaps().');
});

/**
 * Render the mismatch list as a human-readable markdown body.
 *
 * @param  list<array{construct: string, group: string, label: string, payload: array<array-key, mixed>, expected: string, actual: string, violates: string}>  $mismatches
 */
function renderDifferentialReport(array $mismatches): string
{
    $generated = date('Y-m-d');
    $lines = [];
    $lines[] = '# Differential validation findings';
    $lines[] = '';
    $lines[] = 'Oracle: tests/Conformance/DifferentialValidationTest.php (issue #23).';
    $lines[] = 'Generated: '.$generated.'.';
    $lines[] = '';

    if ($mismatches === []) {
        $lines[] = 'No mismatches: every catalog constraint accepted its valid payloads and rejected its invalid payloads.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    $lines[] = 'Each row is a payload whose generated-validator outcome disagrees with the spec.';
    $lines[] = '`expected=reject, actual=accept` means the generated rules SILENTLY ACCEPT data the spec forbids (severity High).';
    $lines[] = '`expected=accept, actual=reject` means the generated rules FALSELY REJECT data the spec allows (severity High/Medium).';
    $lines[] = '';
    $lines[] = '| # | construct | group | violates | label | expected | actual | payload |';
    $lines[] = '| - | --------- | ----- | -------- | ----- | -------- | ------ | ------- |';

    $i = 1;
    foreach ($mismatches as $m) {
        $payload = (string) json_encode($m['payload']);
        $payload = str_replace('|', '\\|', $payload);
        $lines[] = sprintf(
            '| %d | %s | %s | %s | %s | %s | %s | `%s` |',
            $i++,
            $m['construct'],
            $m['group'],
            $m['violates'],
            $m['label'],
            $m['expected'],
            $m['actual'],
            $payload,
        );
    }
    $lines[] = '';

    $gaps = differentialKnownGaps();
    if ($gaps !== []) {
        $lines[] = '## Tracked known gaps';
        $lines[] = '';
        $lines[] = 'These constructs are documented limitations with an open issue. The oracle tolerates them (they do not fail the suite) but fails if they are silently fixed without removing the entry, or if any new, unlisted construct drifts.';
        $lines[] = '';
        foreach ($gaps as $gap) {
            $lines[] = sprintf('- **%s** (%s): %s', $gap['construct'], $gap['issue'], $gap['reason']);
        }
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * @param  list<array{construct: string, group: string, label: string, payload: array<array-key, mixed>, expected: string, actual: string, violates: string}>  $mismatches
 */
function writeDifferentialReport(array $mismatches): void
{
    $path = __DIR__.'/DIFFERENTIAL_FINDINGS.md';
    file_put_contents($path, renderDifferentialReport($mismatches)."\n");
}
