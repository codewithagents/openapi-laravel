<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/*
 * Differential validation oracle for header parameters (issue #121, extending
 * the query oracle of issue #63 and the path oracle of issue #113).
 *
 * Every payload below is fed through the REAL wire path: the values are set as
 * request headers (Symfony lowercases the keys and stores each value as an
 * array, exactly as in production) and validated by the generated class's
 * `fromHeaders()` through the actual Laravel validator. A spec-valid header
 * set that is rejected, or a spec-invalid one that is accepted, fails the
 * suite. The reserved-header skip is also pinned: Accept and Authorization
 * never enter the generated rules, so they can carry anything without
 * affecting the outcome.
 *
 * The validator needs a booted Laravel container, so this test opts into the
 * Testbench TestCase explicitly (the Pest config only binds it under
 * tests/Feature).
 */
uses(TestCase::class);

/**
 * Generate the header oracle's Data class once and load it into the running
 * process. The document routes through the same pre-parse normalization the
 * real pipeline applies, and the header class is emitted by the same collector
 * wiring the planner uses.
 *
 * @return class-string
 */
function headerOracleClass(): string
{
    static $class = null;
    if ($class !== null) {
        return $class;
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'HeaderOracle', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'get' => [
                    'operationId' => 'getThing',
                    'parameters' => [
                        ['name' => 'X-Count', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                        ['name' => 'X-Sort', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
                        ['name' => 'X-Tag', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-z]+$']],
                        ['name' => 'X-State', 'in' => 'header', 'required' => true, 'schema' => ['$ref' => '#/components/schemas/State']],
                        // Reserved headers: declared in the spec, never validated.
                        ['name' => 'Accept', 'in' => 'header', 'schema' => ['type' => 'string', 'enum' => ['application/json']]],
                        ['name' => 'Authorization', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^Bearer .+$']],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'State' => ['type' => 'string', 'enum' => ['on', 'off']],
            ],
        ],
    ];

    $decoded = json_decode((string) json_encode($document), true);
    $spec = (new OpenApiReader)->read($decoded);

    $namespace = 'HeaderOracle\\Models';
    $generator = new ModelGenerator(new GeneratorOptions($namespace));
    $files = $generator->generate($spec);
    (new OperationCollector(new ServerOptions(dataNamespace: $namespace), $generator->registry(), null, $generator))->collect($spec);

    $dir = sys_get_temp_dir().'/oal_header_oracle_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    loadGeneratedFiles($dir, [
        ...array_values($generator->supportFiles()),
        ...array_values($files),
        ...array_values($generator->headerFiles()),
    ]);

    // The oracle operation carries no tag, so its header class lands in the
    // Untagged group of the tag-grouped layout (issue #93, the only layout).
    /** @var class-string $class */
    $class = $namespace.'\\Untagged\\GetThingHeaderData';
    expect(class_exists($class))->toBeTrue('the header oracle class was not generated');

    return $class;
}

/**
 * Run one payload through the wire: set the values as request headers (Symfony
 * lowercases keys and arrays the values), validate and hydrate via the
 * generated fromHeaders().
 *
 * @param  array<string, string>  $payload
 */
function headerOracleOutcome(array $payload): string
{
    $request = Request::create('/', 'GET');
    foreach ($payload as $name => $value) {
        $request->headers->set($name, $value);
    }

    try {
        /** @var callable $factory */
        $factory = [headerOracleClass(), 'fromHeaders'];
        $factory($request);

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.$e->getMessage();
    }
}

dataset('spec_valid_headers', [
    'all parameters valid' => [['X-Count' => '50', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on']],
    'integer at the lower bound' => [['X-Count' => '1', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on']],
    'integer at the upper bound' => [['X-Count' => '100', 'X-Sort' => 'desc', 'X-Tag' => 'abc', 'X-State' => 'off']],
    'enum second member' => [['X-Count' => '50', 'X-Sort' => 'desc', 'X-Tag' => 'abc', 'X-State' => 'on']],
    'component enum member' => [['X-Count' => '50', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'off']],
    'lowercased header names still resolve' => [['x-count' => '50', 'x-sort' => 'asc', 'x-tag' => 'abc', 'x-state' => 'on']],
    'reserved headers carry anything without affecting validation' => [['X-Count' => '50', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on', 'Accept' => 'text/csv', 'Authorization' => 'not-a-bearer-token']],
    'reserved headers omitted entirely' => [['X-Count' => '50', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on']],
]);

dataset('spec_invalid_headers', [
    'integer below minimum' => [['X-Count' => '0', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on']],
    'integer above maximum' => [['X-Count' => '101', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on']],
    'non-integer for an integer parameter' => [['X-Count' => 'soon', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on']],
    'enum value outside the set' => [['X-Count' => '50', 'X-Sort' => 'upward', 'X-Tag' => 'abc', 'X-State' => 'on']],
    'pattern violated' => [['X-Count' => '50', 'X-Sort' => 'asc', 'X-Tag' => 'ABC', 'X-State' => 'on']],
    'component enum value outside the set' => [['X-Count' => '50', 'X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'neither']],
    'required custom header missing' => [['X-Sort' => 'asc', 'X-Tag' => 'abc', 'X-State' => 'on']],
]);

it('accepts every spec-valid header payload through the real validator', function (array $payload) {
    expect(headerOracleOutcome($payload))->toBe('accept');
})->with('spec_valid_headers');

it('rejects every spec-invalid header payload through the real validator', function (array $payload) {
    expect(headerOracleOutcome($payload))->toBe('reject');
})->with('spec_invalid_headers');
