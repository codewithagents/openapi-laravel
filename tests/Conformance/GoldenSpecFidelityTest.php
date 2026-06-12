<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;
use CodeWithAgents\OpenApiLaravel\Tests\Conformance\GoldenFidelityCase;
use CodeWithAgents\OpenApiLaravel\Tests\Conformance\GoldenFidelityCatalog;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/*
 * RUNTIME FIDELITY HARNESS over the golden conformance contract.
 *
 * ConformanceGoldenTest.php proves the conformance fixtures COMPILE and that the
 * generated SOURCE matches per-construct expectations. It never executes the
 * generated classes. This harness fills that gap: it generates every Data class
 * from conformance-3.1.yaml (and the 3.0-forms companion) once, require_once's
 * them, boots Testbench, and then for every runtime-validatable named schema:
 *
 *   - asserts each VALID payload is ACCEPTED by the generated validate(), that
 *     from() hydrates, and (where a roundTrip is given) that from()->toArray()
 *     preserves the value WITHOUT data loss or retyping;
 *   - asserts each INVALID payload is REJECTED.
 *
 * Mismatches (a valid payload rejected, or an invalid payload accepted) are
 * partitioned against the tracked known-gap ratchet in GoldenFidelityCatalog:
 *   - a listed gap is tolerated (documented limitation), but
 *   - an UNLISTED mismatch is new drift and FAILS, and
 *   - a tracked gap that no longer reproduces FAILS, forcing the list to stay
 *     current and honest.
 *
 * The measured per-construct verdict (enforced vs known-gap) is written to
 * tests/Conformance/GOLDEN_FIDELITY.md. The validator needs a booted Laravel
 * container, so this opts into the Testbench TestCase explicitly.
 */
uses(TestCase::class);

const GOLDEN_FIDELITY_31 = __DIR__.'/../Fixtures/conformance/conformance-3.1.yaml';

const GOLDEN_FIDELITY_30 = __DIR__.'/../Fixtures/conformance/conformance-3.0-forms.yaml';

const GOLDEN_FIDELITY_NS = 'GoldenFidelity\\Models';

/**
 * Generate both conformance documents into one temp namespace and require_once
 * every emitted file, once for the whole suite. Abstract bases (the morphable
 * discriminated-union base) are loaded before the variants that extend them.
 *
 * Returns the short class name => FQCN map: under the tag-grouped layout
 * (issue #93, the only layout) a class solely owned by one tag group lives in
 * a per-tag subnamespace, so the catalog's short names must be resolved.
 *
 * @return array<string, class-string>
 */
function goldenFidelityBoot(): array
{
    static $classes = null;
    if ($classes !== null) {
        return $classes;
    }

    $dir = sys_get_temp_dir().'/oal_golden_fidelity_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $classes = [];
    foreach ([GOLDEN_FIDELITY_31, GOLDEN_FIDELITY_30] as $path) {
        $document = (new SpecParser)->parseFile($path);
        $generator = new ModelGenerator(new GeneratorOptions(GOLDEN_FIDELITY_NS));
        $files = $generator->generate($document);
        // The generated rules() reference rule/transformer classes from the
        // consumer's own Support namespace (issue #40), so the inlined support
        // classes must be loaded too or validate() would hit undefined classes.
        $supportFiles = $generator->supportFiles();

        // Load abstract bases first: a variant `extends <Base>Data`, so the base
        // must be declared before the variant is required. The inlined support
        // classes have no inter-dependencies, so load them up front.
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

        loadGeneratedFiles($dir, [...array_values($supportFiles), ...array_values($ordered)]);

        foreach ($files as $name => $file) {
            /** @var class-string $fqcn */
            $fqcn = $generator->namespaceFor($file->className).'\\'.$file->className;
            $classes[$name] = $fqcn;
        }
    }

    return $classes;
}

/**
 * Run one payload through a generated class's validate(). Returns 'accept' when
 * validation passes, 'reject' when it throws ValidationException, or
 * 'error:<class>:<message>' for any other throwable so a generator crash is
 * never mistaken for a clean accept/reject.
 *
 * @param  class-string  $class
 * @param  array<string, mixed>  $payload
 */
function goldenFidelityOutcome(string $class, array $payload): string
{
    try {
        /** @var callable $validator */
        $validator = [$class, 'validate'];
        $validator($payload);

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.get_class($e).':'.$e->getMessage();
    }
}

beforeEach(fn () => goldenFidelityBoot());

it('runs every runtime-validatable conformance construct through the generated wrapper and ratchets the known gaps', function () {
    $classMap = goldenFidelityBoot();
    $cases = [...GoldenFidelityCatalog::cases(), ...GoldenFidelityCatalog::cases30()];

    /** @var list<array{construct: string, class: string, label: string, payload: array<string, mixed>, expected: string, actual: string, violates: string}> $mismatches */
    $mismatches = [];
    /** @var array<string, array{construct: string, valid: int, invalid: int, mismatches: int}> $stats */
    $stats = [];

    foreach ($cases as $case) {
        $class = $classMap[$case->class] ?? GOLDEN_FIDELITY_NS.'\\'.$case->class;
        $stats[$case->construct] ??= ['construct' => $case->construct, 'valid' => 0, 'invalid' => 0, 'mismatches' => 0];

        expect(class_exists($class))->toBeTrue($case->construct.': generated class '.$case->class.' is missing');

        foreach ($case->valid as $entry) {
            $stats[$case->construct]['valid']++;
            $actual = goldenFidelityOutcome($class, $entry['payload']);
            if ($actual !== 'accept') {
                $stats[$case->construct]['mismatches']++;
                $mismatches[] = [
                    'construct' => $case->construct,
                    'class' => $case->class,
                    'label' => $entry['label'],
                    'payload' => $entry['payload'],
                    'expected' => 'accept',
                    'actual' => $actual,
                    'violates' => '(valid payload)',
                ];

                continue;
            }

            // Accepted: hydrate and (when given) assert round-trip fidelity.
            goldenFidelityAssertRoundTrip($case, $class, $entry);
        }

        foreach ($case->invalid as $entry) {
            $stats[$case->construct]['invalid']++;
            $actual = goldenFidelityOutcome($class, $entry['payload']);
            if ($actual !== 'reject') {
                $stats[$case->construct]['mismatches']++;
                $mismatches[] = [
                    'construct' => $case->construct,
                    'class' => $case->class,
                    'label' => $entry['label'],
                    'payload' => $entry['payload'],
                    'expected' => 'reject',
                    'actual' => $actual,
                    'violates' => $entry['violates'],
                ];
            }
        }
    }

    // Always refresh the artifact so it reflects this run.
    goldenFidelityWriteReport($stats, $mismatches);

    // Partition mismatches against the tracked known-gap list.
    $gaps = [];
    foreach (GoldenFidelityCatalog::knownGaps() as $gap) {
        $gaps[GoldenFidelityCatalog::gapKey($gap['class'], $gap['label'], $gap['expected'], $gap['actual'])] = $gap;
    }

    $unexpected = [];
    $seenGapKeys = [];
    foreach ($mismatches as $mismatch) {
        $key = GoldenFidelityCatalog::gapKey($mismatch['class'], $mismatch['label'], $mismatch['expected'], $mismatch['actual']);
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
        fwrite(STDERR, "\n".goldenFidelityRenderMismatches($unexpected)."\n");
    }

    expect($unexpected)->toBe([], 'Golden fidelity harness found '.count($unexpected).' UNEXPECTED mismatch(es), i.e. new drift or a newly discovered bug; see tests/Conformance/GOLDEN_FIDELITY.md');
    expect($resolved)->toBe([], 'Tracked known gap(s) no longer reproduce and were likely fixed: '.implode(', ', $resolved).'. Remove them from GoldenFidelityCatalog::knownGaps().');
});

/**
 * Assert hydration and (when the case provides a roundTrip map) that
 * from()->toArray() restricted to the expected keys preserves every value with
 * no data loss or retyping. Uses strict comparison so an int that becomes a
 * string, or a present-null that vanishes, is caught.
 *
 * @param  class-string  $class
 * @param  array{label: string, payload: array<string, mixed>, roundTrip?: array<string, mixed>}  $entry
 */
function goldenFidelityAssertRoundTrip(GoldenFidelityCase $case, string $class, array $entry): void
{
    /** @var callable $from */
    $from = [$class, 'from'];
    $hydrated = $from($entry['payload']);

    expect($hydrated)->toBeInstanceOf($class, $case->construct.': from() did not hydrate '.$case->class);

    if (! isset($entry['roundTrip'])) {
        return;
    }

    /** @var array<string, mixed> $array */
    $array = $hydrated->toArray();

    foreach ($entry['roundTrip'] as $key => $expected) {
        expect(array_key_exists($key, $array))->toBeTrue($case->construct.' round-trip ['.$entry['label'].']: key '.$key.' missing from toArray()');

        $actual = $array[$key];
        $message = $case->construct.' round-trip ['.$entry['label'].']: '.$key.' lost or retyped (expected '
            .goldenFidelityScalar($expected).', got '.goldenFidelityScalar($actual).')';

        if (is_array($expected)) {
            // A map/collection value may legitimately round-trip as an stdClass
            // (the MapObjectTransformer encodes maps as JSON objects so an empty
            // map stays `{}` not `[]`). Compare value-and-scalar-type through a
            // JSON projection: this still catches a retyped scalar (7 vs "7") and
            // a lost element, while tolerating the array-vs-object map encoding.
            expect(json_encode($actual))->toBe(json_encode($expected), $message);

            continue;
        }

        // Scalars and null compare strictly: an int that became a string, or a
        // present-null that vanished, must fail here.
        expect($actual)->toBe($expected, $message);
    }
}

/**
 * Compact a scalar/array for an assertion message.
 */
function goldenFidelityScalar(mixed $value): string
{
    if (is_array($value)) {
        return (string) json_encode($value);
    }

    return gettype($value).'('.var_export($value, true).')';
}

/**
 * Render a mismatch table as a markdown body shared by STDERR and the artifact.
 *
 * @param  list<array{construct: string, class: string, label: string, payload: array<string, mixed>, expected: string, actual: string, violates: string}>  $mismatches
 */
function goldenFidelityRenderMismatches(array $mismatches): string
{
    $lines = [];
    $lines[] = '| # | construct | class | violates | label | expected | actual | payload |';
    $lines[] = '| - | --------- | ----- | -------- | ----- | -------- | ------ | ------- |';
    $i = 1;
    foreach ($mismatches as $m) {
        $payload = str_replace('|', '\\|', (string) json_encode($m['payload']));
        $actual = str_replace('|', '\\|', $m['actual']);
        $lines[] = sprintf(
            '| %d | %s | %s | %s | %s | %s | %s | `%s` |',
            $i++,
            $m['construct'],
            $m['class'],
            $m['violates'],
            $m['label'],
            $m['expected'],
            $actual,
            $payload,
        );
    }

    return implode("\n", $lines);
}

/**
 * Write tests/Conformance/GOLDEN_FIDELITY.md: the measured, per-construct answer
 * to how much of the comprehensive golden contract the wrapper actually honors.
 *
 * @param  array<string, array{construct: string, valid: int, invalid: int, mismatches: int}>  $stats
 * @param  list<array{construct: string, class: string, label: string, payload: array<string, mixed>, expected: string, actual: string, violates: string}>  $mismatches
 */
function goldenFidelityWriteReport(array $stats, array $mismatches): void
{
    // Map each construct to the issue(s) of any tracked gap it carries.
    $gapByConstruct = [];
    foreach (GoldenFidelityCatalog::knownGaps() as $gap) {
        $gapByConstruct[$gap['construct']][] = $gap;
    }

    $enforced = [];
    $knownGap = [];
    foreach ($stats as $row) {
        if (isset($gapByConstruct[$row['construct']])) {
            $knownGap[] = $row;
        } elseif ($row['mismatches'] === 0) {
            $enforced[] = $row;
        } else {
            // A construct with an unlisted mismatch: the suite will fail; still
            // record it here under its own heading so the artifact is truthful.
            $knownGap[] = $row;
        }
    }

    $lines = [];
    $lines[] = '# Golden conformance fidelity';
    $lines[] = '';
    $lines[] = 'Harness: tests/Conformance/GoldenSpecFidelityTest.php.';
    $lines[] = 'Generated: '.date('Y-m-d').'.';
    $lines[] = '';
    $lines[] = 'This is the measured runtime answer to "how much of the comprehensive golden';
    $lines[] = 'conformance contract does the generated wrapper actually honor". Every row is a';
    $lines[] = 'named conformance schema driven through the generated validate()/from() with the';
    $lines[] = 'real Laravel validator: valid payloads must be accepted (and round-trip without';
    $lines[] = 'data loss or retyping), invalid payloads must be rejected.';
    $lines[] = '';
    $lines[] = sprintf(
        '**Headline:** %d construct(s) FULLY ENFORCED, %d tracked KNOWN-GAP, out of %d total.',
        count($enforced),
        count($knownGap),
        count($stats),
    );
    $lines[] = '';
    $lines[] = '## Fully enforced';
    $lines[] = '';
    $lines[] = 'Valid payloads accepted, invalid payloads rejected, round-trips clean.';
    $lines[] = '';
    $lines[] = '| construct | valid cases | invalid cases |';
    $lines[] = '| --------- | ----------- | ------------- |';
    foreach ($enforced as $row) {
        $lines[] = sprintf('| %s | %d | %d |', $row['construct'], $row['valid'], $row['invalid']);
    }
    $lines[] = '';
    $lines[] = '## Tracked known gaps';
    $lines[] = '';
    $lines[] = 'Constructs the generator knowingly under-enforces. The harness asserts the';
    $lines[] = 'CURRENT (lenient) behavior and tolerates it, but fails if the gap is silently';
    $lines[] = 'fixed without removing its entry, or if any new construct drifts.';
    $lines[] = '';
    foreach (GoldenFidelityCatalog::knownGaps() as $gap) {
        $lines[] = sprintf(
            '- **%s** (%s), %s: expected `%s`, actual `%s`. %s',
            $gap['construct'],
            $gap['issue'],
            $gap['class'],
            $gap['expected'],
            $gap['actual'],
            $gap['reason'],
        );
    }
    $lines[] = '';

    if ($mismatches !== []) {
        $lines[] = '## Observed mismatches this run';
        $lines[] = '';
        $lines[] = 'Every accept/reject disagreement the harness observed (tracked gaps included).';
        $lines[] = 'Any row NOT covered by a tracked known gap above fails the suite.';
        $lines[] = '';
        $lines[] = goldenFidelityRenderMismatches($mismatches);
        $lines[] = '';
    }

    file_put_contents(__DIR__.'/GOLDEN_FIDELITY.md', implode("\n", $lines)."\n");
}
