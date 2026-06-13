<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/*
 * Differential validation oracle for inline (non-$ref) object responses (issue
 * #129, the symmetric twin of the inline request body oracle of issue #76).
 *
 * The synthesized `<Operation>ResponseData` class goes through the exact
 * emitData pipeline a component schema and an inline request body use, but the
 * synthesis path (operation collector -> responseType -> generateInlineResponseData
 * -> response bucket) is new, so this oracle proves end to end that an INLINE
 * response's spec constraints are enforced by the real Laravel validator, and
 * that the READ variant is emitted (writeOnly dropped, readOnly kept), the
 * opposite of the request body's write variant.
 *
 * The validator needs a booted Laravel container, so this test opts into the
 * Testbench TestCase explicitly (the Pest config only binds it under
 * tests/Feature).
 */
uses(TestCase::class);

/**
 * Generate the inline-response oracle's classes once and load them into the
 * running process. The response carries a readOnly field (kept) and a
 * writeOnly field (dropped on the READ variant), so the same class proves both
 * the constraint enforcement and the variant selection.
 *
 * @return class-string
 */
function inlineResponseOracleClass(): string
{
    static $class = null;
    if ($class !== null) {
        return $class;
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'InlineResponseOracle', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'get' => [
                    'operationId' => 'getThing',
                    'responses' => ['200' => [
                        'description' => 'a thing',
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['name', 'home'],
                            'additionalProperties' => false,
                            'properties' => [
                                'id' => ['type' => 'integer', 'minimum' => 1, 'readOnly' => true],
                                'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 10],
                                'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                                'sort' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                                'state' => ['$ref' => '#/components/schemas/State'],
                                'home' => [
                                    'type' => 'object',
                                    'required' => ['city'],
                                    'properties' => ['city' => ['type' => 'string', 'minLength' => 2]],
                                ],
                                'secret' => ['type' => 'string', 'writeOnly' => true],
                            ],
                        ]]],
                    ]],
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

    $namespace = 'InlineResponseOracle\\Models';
    $generator = new ModelGenerator(new GeneratorOptions($namespace));
    $files = $generator->generate($spec);
    (new OperationCollector(new ServerOptions(dataNamespace: $namespace), $generator->registry(), null, $generator))->collect($spec);

    $dir = sys_get_temp_dir().'/oal_inline_response_oracle_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    loadGeneratedFiles($dir, [
        ...array_values($generator->supportFiles()),
        ...array_values($files),
        ...array_values($generator->responseFiles()),
    ]);

    // The oracle operation carries no tag, so its response class lands in the
    // Untagged group of the tag-grouped layout (issue #93, the only layout).
    /** @var class-string $class */
    $class = $namespace.'\\Untagged\\GetThingResponseData';
    expect(class_exists($class))->toBeTrue('the inline-response oracle class was not generated');

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
function inlineResponseOracleOutcome(array $payload): string
{
    try {
        /** @var callable $validator */
        $validator = [inlineResponseOracleClass(), 'validate'];
        $validator($payload);

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.$e->getMessage();
    }
}

dataset('spec_valid_inline_responses', [
    'all read fields present and valid' => [['id' => 1, 'name' => 'ab', 'count' => 100, 'sort' => 'asc', 'state' => 'on', 'home' => ['city' => 'Rome']]],
    'only the required fields' => [['name' => 'ab', 'home' => ['city' => 'Rome']]],
    'readOnly id at the lower bound' => [['id' => 1, 'name' => 'ab', 'home' => ['city' => 'Rome']]],
    'enum second member' => [['name' => 'ab', 'sort' => 'desc', 'home' => ['city' => 'Rome']]],
    'component enum member' => [['name' => 'ab', 'state' => 'off', 'home' => ['city' => 'Rome']]],
]);

dataset('spec_invalid_inline_responses', [
    'missing required name' => [['home' => ['city' => 'Rome']]],
    'missing required nested object' => [['name' => 'ab']],
    'string below minLength' => [['name' => 'a', 'home' => ['city' => 'Rome']]],
    'integer above maximum' => [['name' => 'ab', 'count' => 101, 'home' => ['city' => 'Rome']]],
    'readOnly integer below its minimum' => [['id' => 0, 'name' => 'ab', 'home' => ['city' => 'Rome']]],
    'enum value outside the set' => [['name' => 'ab', 'sort' => 'upward', 'home' => ['city' => 'Rome']]],
    'nested required field missing' => [['name' => 'ab', 'home' => []]],
    'unknown key on the closed object' => [['name' => 'ab', 'home' => ['city' => 'Rome'], 'extra' => 1]],
]);

it('accepts every spec-valid inline response payload through the real validator', function (array $payload) {
    expect(inlineResponseOracleOutcome($payload))->toBe('accept');
})->with('spec_valid_inline_responses');

it('rejects every spec-invalid inline response payload through the real validator', function (array $payload) {
    expect(inlineResponseOracleOutcome($payload))->toBe('reject');
})->with('spec_invalid_inline_responses');

it('emits the READ variant: the writeOnly secret field is dropped, the readOnly id field kept', function () {
    $class = inlineResponseOracleClass();

    // A response is server OUTPUT: the writeOnly property never appears on the
    // generated class, so a payload carrying it is rejected by the closed
    // object (it is now an unknown key), while the readOnly property is a
    // first-class field.
    expect(property_exists($class, 'id'))->toBeTrue()
        ->and(property_exists($class, 'secret'))->toBeFalse()
        ->and(inlineResponseOracleOutcome(['name' => 'ab', 'home' => ['city' => 'Rome'], 'secret' => 'x']))->toBe('reject');
});
