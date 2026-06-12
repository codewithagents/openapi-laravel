<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/*
 * Differential validation oracle for query parameters (issue #63, extending
 * the body oracle of issue #23).
 *
 * Every payload below is fed through the REAL wire path: it is serialized
 * into a query string with http_build_query(), parsed back by a real
 * Illuminate Request (so scalars arrive as strings and `ids[]=` arrives as an
 * array, exactly as in production), and validated by the generated class's
 * `fromQuery()` through the actual Laravel validator. A spec-valid query that
 * is rejected, or a spec-invalid query that is accepted, fails the suite.
 *
 * The validator needs a booted Laravel container, so this test opts into the
 * Testbench TestCase explicitly (the Pest config only binds it under
 * tests/Feature).
 */
uses(TestCase::class);

/**
 * Generate the query oracle's Data classes once and load them into the
 * running process. The document routes through the same pre-parse
 * normalization the real pipeline applies, and the query class is emitted by
 * the same collector wiring the planner uses.
 *
 * @return class-string
 */
function queryOracleClass(): string
{
    static $class = null;
    if ($class !== null) {
        return $class;
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'QueryOracle', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'get' => [
                    'operationId' => 'listThings',
                    'parameters' => [
                        ['name' => 'name', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 10]],
                        ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                        ['name' => 'ratio', 'in' => 'query', 'schema' => ['type' => 'number', 'multipleOf' => 0.5]],
                        ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
                        ['name' => 'active', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                        ['name' => 'ids', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]]],
                        ['name' => 'tag', 'in' => 'query', 'schema' => ['type' => 'string', 'pattern' => '^[a-z]+$']],
                        ['name' => 'after', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
                        ['name' => 'state', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/State']],
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
    $normalized = SchemaNormalizer::normalize($decoded);
    $spec = Reader::readFromJson((string) json_encode($normalized), OpenApi::class);

    $namespace = 'QueryOracle\\Models';
    $generator = new ModelGenerator(new GeneratorOptions($namespace));
    $files = $generator->generate($spec);
    (new OperationCollector(new ServerOptions(dataNamespace: $namespace), $generator->registry(), null, $generator))->collect($spec);

    $dir = sys_get_temp_dir().'/oal_query_oracle_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    loadGeneratedFiles($dir, [
        ...array_values($generator->supportFiles()),
        ...array_values($files),
        ...array_values($generator->queryFiles()),
    ]);

    // The oracle operation carries no tag, so its query class lands in the
    // Untagged group of the tag-grouped layout (issue #93, the only layout).
    /** @var class-string $class */
    $class = $namespace.'\\Untagged\\ListThingsQueryData';
    expect(class_exists($class))->toBeTrue('the query oracle class was not generated');

    return $class;
}

/**
 * Run one payload through the wire: serialize to a query string, parse it back
 * with a real Request, validate and hydrate via the generated fromQuery().
 *
 * @param  array<string, mixed>  $payload
 */
function queryOracleOutcome(array $payload): string
{
    $uri = '/things'.($payload === [] ? '' : '?'.http_build_query($payload));

    try {
        /** @var callable $factory */
        $factory = [queryOracleClass(), 'fromQuery'];
        $factory(Request::create($uri, 'GET'));

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.$e->getMessage();
    }
}

dataset('spec_valid_queries', [
    'all parameters present and valid' => [['name' => 'ab', 'limit' => '100', 'ratio' => '1.5', 'sort' => 'asc', 'active' => 'true', 'ids' => ['1', '2'], 'tag' => 'abc', 'after' => '2026-01-01', 'state' => 'on']],
    'only the required parameter' => [['name' => 'ab']],
    'integer at the lower bound' => [['name' => 'ab', 'limit' => '1']],
    'integer at the upper bound' => [['name' => 'ab', 'limit' => '100']],
    'multipleOf satisfied by a whole number' => [['name' => 'ab', 'ratio' => '3']],
    'boolean literal true' => [['name' => 'ab', 'active' => 'true']],
    'boolean literal false' => [['name' => 'ab', 'active' => 'false']],
    'boolean as 1' => [['name' => 'ab', 'active' => '1']],
    'boolean as 0' => [['name' => 'ab', 'active' => '0']],
    'single-element exploded array' => [['name' => 'ab', 'ids' => ['7']]],
    'enum second member' => [['name' => 'ab', 'sort' => 'desc']],
    'component enum member' => [['name' => 'ab', 'state' => 'off']],
    'date at a month boundary' => [['name' => 'ab', 'after' => '2026-02-28']],
]);

dataset('spec_invalid_queries', [
    'missing required parameter' => [[]],
    'string below minLength' => [['name' => 'a']],
    'string above maxLength' => [['name' => 'abcdefghijk']],
    'integer below minimum' => [['name' => 'ab', 'limit' => '0']],
    'integer above maximum' => [['name' => 'ab', 'limit' => '101']],
    'non-integer for an integer parameter' => [['name' => 'ab', 'limit' => 'soon']],
    'multipleOf violated' => [['name' => 'ab', 'ratio' => '0.3']],
    'enum value outside the set' => [['name' => 'ab', 'sort' => 'upward']],
    'non-boolean for a boolean parameter' => [['name' => 'ab', 'active' => 'banana']],
    'scalar where an array is declared' => [['name' => 'ab', 'ids' => '7']],
    'array element below the item minimum' => [['name' => 'ab', 'ids' => ['0']]],
    'array element of the wrong type' => [['name' => 'ab', 'ids' => ['x']]],
    'pattern violated' => [['name' => 'ab', 'tag' => 'ABC']],
    'malformed date' => [['name' => 'ab', 'after' => 'tomorrow']],
    'impossible calendar date' => [['name' => 'ab', 'after' => '2026-13-40']],
    'component enum value outside the set' => [['name' => 'ab', 'state' => 'neither']],
]);

it('accepts every spec-valid query payload through the real validator', function (array $payload) {
    expect(queryOracleOutcome($payload))->toBe('accept');
})->with('spec_valid_queries');

it('rejects every spec-invalid query payload through the real validator', function (array $payload) {
    expect(queryOracleOutcome($payload))->toBe('reject');
})->with('spec_invalid_queries');
