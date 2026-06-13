<?php

declare(strict_types=1);

use App\ClosedStrict\AccountData;
use App\ClosedStrict\OuterData;
use App\ClosedStrict\ShelfData;
use App\ClosedStrict\TaggedData;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for opt-in `additionalProperties: false` enforcement (issue
 * #30). Generates the SAME closed schema twice, once with enforceClosedObjects
 * on and once off, into two distinct namespaces, loads both into the booted
 * Testbench app, and runs the generated rules() through the real Laravel
 * validator. Proves the emitted NoUnknownPropertiesRule actually rejects an
 * unknown key with the flag on, and that the default (off) output still accepts
 * it, mirroring tests/Feature/Emitter/ConstraintValidationRoundTripTest.php.
 */
function bootClosedObjectClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $schemas = [
        'Account' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'label' => ['type' => 'string'],
            ],
        ],
        // patternProperties + additionalProperties: false (issue #65): a key
        // matching a pattern is spec-legal, so the closed-object rule must
        // admit it while still rejecting keys matching nothing.
        'Tagged' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'patternProperties' => [
                '^x-' => ['type' => 'string'],
            ],
        ],
        // A closed object NESTED as a property of another (also closed) object
        // (#30 nested-recursion bug). laravel-data hands the inner rule the FULL
        // top-level payload via setData(), so before the fix the inner rule
        // compared the ROOT keys (outer, inner) against the INNER allow-list and
        // rejected every valid payload. The rule must scope to its own subtree.
        'Outer' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['inner'],
            'properties' => [
                'outer' => ['type' => 'string'],
                'inner' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['value'],
                    'properties' => [
                        'value' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        // A closed object inside a COLLECTION. Each element's rule is keyed under
        // its concrete index path (items.0, items.1, ...), so the rule must scope
        // to that element's subtree, not the root.
        'Shelf' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['sku'],
                        'properties' => [
                            'sku' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Closed', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    $base = sys_get_temp_dir().'/oal_closed_'.getmypid();

    // Strict variant: enforcement on, into App\ClosedStrict.
    $strictGen = new ModelGenerator(new GeneratorOptions('App\\ClosedStrict', 'Data', 64, true));
    $strict = $strictGen->generate($spec);
    // Lenient variant (default behavior): enforcement off, into App\ClosedLenient.
    $lenientGen = new ModelGenerator(new GeneratorOptions('App\\ClosedLenient', 'Data', 64, false));
    $lenient = $lenientGen->generate($spec);

    // The strict variant references NoUnknownPropertiesRule from its own Support
    // namespace (issue #40), so the inlined support classes must be written and
    // loaded too or the rule reference would be undefined at runtime.
    $variants = [
        'strict' => [...array_values($strict), ...array_values($strictGen->supportFiles())],
        'lenient' => [...array_values($lenient), ...array_values($lenientGen->supportFiles())],
    ];

    // Both variants emit AccountData.php, so write each into its own directory to
    // avoid the second require_once shadowing the first on a shared filename.
    foreach ($variants as $label => $files) {
        $dir = $base.'/'.$label;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach ($files as $file) {
            $path = $dir.'/'.$file->filename();
            file_put_contents($path, $file->code);
            require_once $path;
        }
    }

    $booted = true;
}

beforeEach(fn () => bootClosedObjectClasses());

it('accepts a payload with only declared keys when enforcement is on', function () {
    AccountData::validate(['id' => 1, 'label' => 'main']);
    expect(true)->toBeTrue();
});

it('rejects a payload with an unknown key when enforcement is on (#30)', function () {
    AccountData::validate(['id' => 1, 'extra' => 'nope']);
})->throws(ValidationException::class);

it('rejects an unknown key even with no declared optional key present (#30)', function () {
    AccountData::validate(['id' => 1, 'surprise' => true]);
})->throws(ValidationException::class);

it('accepts a key permitted by patternProperties when enforcement is on (#65)', function () {
    TaggedData::validate(['id' => 1, 'x-trace' => 'abc']);
    expect(true)->toBeTrue();
});

it('still rejects a key matching neither declared names nor patternProperties (#65)', function () {
    TaggedData::validate(['id' => 1, 'rogue' => 'nope']);
})->throws(ValidationException::class);

it('accepts an unknown key when enforcement is off (default behavior, the documented gap)', function () {
    // This is exactly today's lenient default: the unknown key is silently
    // accepted. The known-gap ratchet documents this; enforcement is opt in.
    App\ClosedLenient\AccountData::validate(['id' => 1, 'extra' => 'accepted']);
    expect(true)->toBeTrue();
});

it('accepts a clean payload for a NESTED closed object (#30 nested recursion)', function () {
    // Before the fix this false-rejected with the inner rule seeing the root keys
    // (outer, inner) against the inner allow-list ([value]).
    OuterData::validate(['outer' => 'top', 'inner' => ['value' => 'ok']]);
    expect(true)->toBeTrue();
});

it('rejects an unknown key on the INNER object keyed on the nested path (#30 nested recursion)', function () {
    try {
        OuterData::validate(['inner' => ['value' => 'ok', 'rogue' => 'nope']]);
        $this->fail('expected a ValidationException for the unknown inner key');
    } catch (ValidationException $e) {
        // The failure must be keyed on the nested sentinel path, not the root.
        // The reported unknown-keys list (after the colon) must name the inner
        // unknown key (rogue) and NOT the root keys (outer), proving the rule
        // scoped to its own subtree rather than the top-level payload.
        $errors = $e->errors();
        expect($errors)->toHaveKey('inner.__openapi_laravel_no_unknown_properties');
        $unknownList = substr($errors['inner.__openapi_laravel_no_unknown_properties'][0], strrpos($errors['inner.__openapi_laravel_no_unknown_properties'][0], ':') + 1);
        expect($unknownList)->toContain('rogue')
            ->and($unknownList)->not->toContain('outer');
    }
});

it('accepts a clean inner object on the OUTER closed object (#30 nested recursion)', function () {
    // The outer rule must police only the outer keys (outer, inner), so a valid
    // nested object must not trip the outer closed-object rule.
    OuterData::validate(['inner' => ['value' => 'ok']]);
    expect(true)->toBeTrue();
});

it('accepts a clean payload for a closed object inside a COLLECTION (#30 nested recursion)', function () {
    ShelfData::validate(['items' => [['sku' => 'a'], ['sku' => 'b']]]);
    expect(true)->toBeTrue();
});

it('rejects an unknown key on a COLLECTION element keyed on the indexed path (#30 nested recursion)', function () {
    try {
        ShelfData::validate(['items' => [['sku' => 'a'], ['sku' => 'b', 'rogue' => 'nope']]]);
        $this->fail('expected a ValidationException for the unknown element key');
    } catch (ValidationException $e) {
        $errors = $e->errors();
        expect($errors)->toHaveKey('items.1.__openapi_laravel_no_unknown_properties')
            ->and($errors['items.1.__openapi_laravel_no_unknown_properties'][0])->toContain('rogue');
    }
});
