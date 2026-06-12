<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/*
 * Differential validation oracle for inline request bodies (issue #76,
 * extending the body oracle of issue #23 and the query oracle of issue #63).
 *
 * The synthesized `<Operation>RequestData` class goes through the exact
 * emitData pipeline a component schema uses, but the synthesis path (operation
 * collector -> generateBodyData -> body bucket) is new, so this oracle proves
 * end to end that an INLINE body's spec constraints are enforced by the real
 * Laravel validator: every spec-valid payload must be accepted and every
 * spec-invalid payload rejected, through the same validate() hook the
 * component-class oracle uses.
 *
 * The validator needs a booted Laravel container, so this test opts into the
 * Testbench TestCase explicitly (the Pest config only binds it under
 * tests/Feature).
 */
uses(TestCase::class);

/**
 * Generate the inline-body oracle's classes once and load them into the
 * running process. The document routes through the same pre-parse
 * normalization the real pipeline applies, and the body class is emitted by
 * the same collector wiring the planner uses.
 *
 * @return class-string
 */
function inlineBodyOracleClass(): string
{
    static $class = null;
    if ($class !== null) {
        return $class;
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'InlineBodyOracle', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'post' => [
                    'operationId' => 'createThing',
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['name', 'home'],
                            'additionalProperties' => false,
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 10],
                                'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                                'ratio' => ['type' => 'number', 'multipleOf' => 0.5],
                                'sort' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                                'state' => ['$ref' => '#/components/schemas/State'],
                                'ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                                'home' => [
                                    'type' => 'object',
                                    'required' => ['city'],
                                    'properties' => ['city' => ['type' => 'string', 'minLength' => 2]],
                                ],
                            ],
                        ]]],
                    ],
                    'responses' => ['201' => ['description' => 'created']],
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
    $spec = (new OpenApiReader)->read($normalized);
    $specCebe = Reader::readFromJson((string) json_encode($normalized), OpenApi::class);

    $namespace = 'InlineBodyOracle\\Models';
    $generator = new ModelGenerator(new GeneratorOptions($namespace));
    $files = $generator->generate($spec);
    (new OperationCollector(new ServerOptions(dataNamespace: $namespace), $generator->registry(), null, $generator))->collect($specCebe);

    $dir = sys_get_temp_dir().'/oal_inline_body_oracle_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    loadGeneratedFiles($dir, [
        ...array_values($generator->supportFiles()),
        ...array_values($files),
        ...array_values($generator->bodyFiles()),
    ]);

    // The oracle operation carries no tag, so its body class lands in the
    // Untagged group of the tag-grouped layout (issue #93, the only layout).
    /** @var class-string $class */
    $class = $namespace.'\\Untagged\\CreateThingRequestData';
    expect(class_exists($class))->toBeTrue('the inline-body oracle class was not generated');

    return $class;
}

/**
 * Run one payload through the synthesized class's validate(). Returns 'accept'
 * when validation passes and 'reject' when it throws ValidationException; any
 * other throwable surfaces as 'error:<message>' so a generator crash is never
 * mistaken for a clean outcome.
 *
 * @param  array<string, mixed>  $payload
 */
function inlineBodyOracleOutcome(array $payload): string
{
    try {
        /** @var callable $validator */
        $validator = [inlineBodyOracleClass(), 'validate'];
        $validator($payload);

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.$e->getMessage();
    }
}

dataset('spec_valid_inline_bodies', [
    'all fields present and valid' => [['name' => 'ab', 'count' => 100, 'ratio' => 1.5, 'sort' => 'asc', 'state' => 'on', 'ids' => [1, 2], 'home' => ['city' => 'Rome']]],
    'only the required fields' => [['name' => 'ab', 'home' => ['city' => 'Rome']]],
    'integer at the lower bound' => [['name' => 'ab', 'count' => 1, 'home' => ['city' => 'Rome']]],
    'multipleOf satisfied by a whole number' => [['name' => 'ab', 'ratio' => 3, 'home' => ['city' => 'Rome']]],
    'enum second member' => [['name' => 'ab', 'sort' => 'desc', 'home' => ['city' => 'Rome']]],
    'component enum member' => [['name' => 'ab', 'state' => 'off', 'home' => ['city' => 'Rome']]],
    'single-element array' => [['name' => 'ab', 'ids' => [7], 'home' => ['city' => 'Rome']]],
]);

dataset('spec_invalid_inline_bodies', [
    'missing required name' => [['home' => ['city' => 'Rome']]],
    'missing required nested object' => [['name' => 'ab']],
    'string below minLength' => [['name' => 'a', 'home' => ['city' => 'Rome']]],
    'string above maxLength' => [['name' => 'abcdefghijk', 'home' => ['city' => 'Rome']]],
    'integer below minimum' => [['name' => 'ab', 'count' => 0, 'home' => ['city' => 'Rome']]],
    'integer above maximum' => [['name' => 'ab', 'count' => 101, 'home' => ['city' => 'Rome']]],
    'non-integer for an integer field' => [['name' => 'ab', 'count' => 'soon', 'home' => ['city' => 'Rome']]],
    'multipleOf violated' => [['name' => 'ab', 'ratio' => 0.3, 'home' => ['city' => 'Rome']]],
    'enum value outside the set' => [['name' => 'ab', 'sort' => 'upward', 'home' => ['city' => 'Rome']]],
    'component enum value outside the set' => [['name' => 'ab', 'state' => 'neither', 'home' => ['city' => 'Rome']]],
    'array element below the item minimum' => [['name' => 'ab', 'ids' => [0], 'home' => ['city' => 'Rome']]],
    'array element of the wrong type' => [['name' => 'ab', 'ids' => ['x'], 'home' => ['city' => 'Rome']]],
    'scalar where an array is declared' => [['name' => 'ab', 'ids' => 7, 'home' => ['city' => 'Rome']]],
    'nested required field missing' => [['name' => 'ab', 'home' => []]],
    'nested field violating minLength' => [['name' => 'ab', 'home' => ['city' => 'R']]],
    'unknown key on the closed object' => [['name' => 'ab', 'home' => ['city' => 'Rome'], 'extra' => 1]],
]);

it('accepts every spec-valid inline body payload through the real validator', function (array $payload) {
    expect(inlineBodyOracleOutcome($payload))->toBe('accept');
})->with('spec_valid_inline_bodies');

it('rejects every spec-invalid inline body payload through the real validator', function (array $payload) {
    expect(inlineBodyOracleOutcome($payload))->toBe('reject');
})->with('spec_invalid_inline_bodies');
