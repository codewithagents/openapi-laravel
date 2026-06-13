<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

/*
 * Differential validation oracle for path parameters (issue #113, extending
 * the query oracle of issue #63).
 *
 * Every payload below is fed through the REAL wire path: the values are bound
 * into a route's resolved parameters (path segments are always strings,
 * exactly as Laravel's router produces them), and validated by the generated
 * class's `fromRoute()` through the actual Laravel validator. A spec-valid
 * path that is rejected, or a spec-invalid path that is accepted, fails the
 * suite.
 *
 * The validator needs a booted Laravel container, so this test opts into the
 * Testbench TestCase explicitly (the Pest config only binds it under
 * tests/Feature).
 */
uses(TestCase::class);

/**
 * Generate the path oracle's Data class once and load it into the running
 * process. The document routes through the same pre-parse normalization the
 * real pipeline applies, and the path class is emitted by the same collector
 * wiring the planner uses.
 *
 * @return class-string
 */
function pathOracleClass(): string
{
    static $class = null;
    if ($class !== null) {
        return $class;
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'PathOracle', 'version' => '1.0.0'],
        'paths' => [
            '/things/{id}/{ratio}/{sort}/{tag}/{after}/{state}' => [
                'get' => [
                    'operationId' => 'getThing',
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                        ['name' => 'ratio', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'number', 'multipleOf' => 0.5]],
                        ['name' => 'sort', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
                        ['name' => 'tag', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-z]+$']],
                        ['name' => 'after', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date']],
                        ['name' => 'state', 'in' => 'path', 'required' => true, 'schema' => ['$ref' => '#/components/schemas/State']],
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

    $namespace = 'PathOracle\\Models';
    $generator = new ModelGenerator(new GeneratorOptions($namespace));
    $files = $generator->generate($spec);
    (new OperationCollector(new ServerOptions(dataNamespace: $namespace), $generator->registry(), null, $generator))->collect($spec);

    $dir = sys_get_temp_dir().'/oal_path_oracle_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    loadGeneratedFiles($dir, [
        ...array_values($generator->supportFiles()),
        ...array_values($files),
        ...array_values($generator->pathFiles()),
    ]);

    // The oracle operation carries no tag, so its path class lands in the
    // Untagged group of the tag-grouped layout (issue #93, the only layout).
    /** @var class-string $class */
    $class = $namespace.'\\Untagged\\GetThingPathData';
    expect(class_exists($class))->toBeTrue('the path oracle class was not generated');

    return $class;
}

/**
 * Run one payload through the wire: bind the values as a route's resolved
 * parameters (always strings, as Laravel's router produces them), validate and
 * hydrate via the generated fromRoute().
 *
 * @param  array<string, string>  $payload
 */
function pathOracleOutcome(array $payload): string
{
    $request = Request::create('/', 'GET');
    $route = new Route('GET', '/', []);
    $route->bind($request);
    foreach ($payload as $name => $value) {
        $route->setParameter($name, $value);
    }
    $request->setRouteResolver(fn () => $route);

    try {
        /** @var callable $factory */
        $factory = [pathOracleClass(), 'fromRoute'];
        $factory($request);

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.$e->getMessage();
    }
}

dataset('spec_valid_paths', [
    'all parameters valid' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'integer at the lower bound' => [['id' => '1', 'ratio' => '0.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'integer at the upper bound' => [['id' => '100', 'ratio' => '0.5', 'sort' => 'desc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'off']],
    'multipleOf satisfied by a whole number' => [['id' => '50', 'ratio' => '3', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'enum second member' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'desc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'component enum member' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'off']],
    'date at a month boundary' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-02-28', 'state' => 'on']],
]);

dataset('spec_invalid_paths', [
    'integer below minimum' => [['id' => '0', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'integer above maximum' => [['id' => '101', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'non-integer for an integer parameter' => [['id' => 'soon', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'multipleOf violated' => [['id' => '50', 'ratio' => '0.3', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'enum value outside the set' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'upward', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'pattern violated' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'ABC', 'after' => '2026-01-01', 'state' => 'on']],
    'malformed date' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => 'tomorrow', 'state' => 'on']],
    'impossible calendar date' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-13-40', 'state' => 'on']],
    'component enum value outside the set' => [['id' => '50', 'ratio' => '1.5', 'sort' => 'asc', 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'neither']],
]);

it('accepts every spec-valid path payload through the real validator', function (array $payload) {
    expect(pathOracleOutcome($payload))->toBe('accept');
})->with('spec_valid_paths');

it('rejects every spec-invalid path payload through the real validator', function (array $payload) {
    expect(pathOracleOutcome($payload))->toBe('reject');
})->with('spec_invalid_paths');
