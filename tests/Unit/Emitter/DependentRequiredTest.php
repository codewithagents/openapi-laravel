<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * dependentRequired emission (issue #81). The JSON Schema keyword
 * `dependentRequired: { trigger: [dependent, ...] }` means: when `trigger` is
 * present, every listed dependent must be present too. It maps onto Laravel's
 * `required_with:trigger` on the DEPENDENT (or `present_with:` for a nullable
 * dependent, mirroring the required/present presence split). A dependent
 * required by several triggers merges them into one `required_with:a,b` rule,
 * matching required_with's any-of semantics.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateDependentRequiredSchemas(array $schemas, ?ModelGenerator $generator = null): array
{
    $document = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read(json_decode((string) json_encode($document), true));
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return ($generator ?? new ModelGenerator)->generate($spec);
}

it('emits required_with on the dependent of a single trigger', function () {
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ],
    ]);

    expect($files['PaymentData']->code)
        ->toContain("'billingAddress' => ['required_with:creditCard', 'string'],")
        // The trigger itself stays untouched.
        ->toContain("'creditCard' => ['sometimes', 'string'],");
});

it('emits required_with on every dependent when one trigger requires several', function () {
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
                'cvv' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['billingAddress', 'cvv']],
        ],
    ]);

    expect($files['PaymentData']->code)
        ->toContain("'billingAddress' => ['required_with:creditCard', 'string'],")
        ->toContain("'cvv' => ['required_with:creditCard', 'string'],");
});

it('merges several triggers of the same dependent into one any-of required_with rule', function () {
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'paypal' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
            ],
            'dependentRequired' => [
                'creditCard' => ['billingAddress'],
                'paypal' => ['billingAddress'],
            ],
        ],
    ]);

    // Each trigger independently requires the dependent (JSON Schema ANDs the
    // entries), which is exactly required_with's "required when ANY of the
    // listed fields is present".
    expect($files['PaymentData']->code)
        ->toContain("'billingAddress' => ['required_with:creditCard,paypal', 'string'],");
});

it('emits present_with for a nullable dependent so a present null is not falsely rejected', function () {
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'billingAddress' => ['type' => ['string', 'null']],
            ],
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ],
    ]);

    expect($files['PaymentData']->code)
        ->toContain("'billingAddress' => ['present_with:creditCard', 'nullable', 'string'],");
});

it('keeps a spec-required dependent unconditionally required (required_with adds nothing)', function () {
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'required' => ['billingAddress'],
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ],
    ]);

    expect($files['PaymentData']->code)
        ->toContain("'billingAddress' => ['required', 'string'],")
        ->not->toContain('required_with');
});

it('still emits the rule for a dependent the schema does not declare as a property', function () {
    // JSON Schema allows a dependency on an undeclared name (it may arrive via
    // additionalProperties); the rule is keyed by the wire name regardless.
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['voucherCode']],
        ],
    ]);

    expect($files['PaymentData']->code)
        ->toContain("'voucherCode' => ['required_with:creditCard'],");
});

it('drops the rule from a variant class whose read/write split removed the dependent', function () {
    $files = generateDependentRequiredSchemas([
        'Account' => [
            'type' => 'object',
            'properties' => [
                'nickname' => ['type' => 'string'],
                'auditRef' => ['type' => 'string', 'readOnly' => true],
            ],
            'dependentRequired' => ['nickname' => ['auditRef']],
        ],
    ]);

    // The read class keeps the readOnly dependent, so the rule applies there.
    expect($files['AccountData']->code)
        ->toContain("'auditRef' => ['required_with:nickname', 'string'],");

    // The write class dropped auditRef: conditionally requiring a field the
    // class cannot carry would reject every nickname-bearing payload.
    expect($files['AccountWritableData']->code)
        ->not->toContain('required_with');
});

it('merges dependentRequired declared inside allOf members', function () {
    $files = generateDependentRequiredSchemas([
        'Base' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['billingAddress']],
        ],
        'Composed' => [
            'type' => 'object',
            'allOf' => [
                ['$ref' => '#/components/schemas/Base'],
                [
                    'type' => 'object',
                    'properties' => ['coupon' => ['type' => 'string'], 'couponSource' => ['type' => 'string']],
                    'dependentRequired' => ['coupon' => ['couponSource']],
                ],
            ],
        ],
    ]);

    expect($files['ComposedData']->code)
        ->toContain("'billingAddress' => ['required_with:creditCard', 'string'],")
        ->toContain("'couponSource' => ['required_with:coupon', 'string'],");
});

it('applies dependentRequired inside a discriminated allOf-inheritance variant', function () {
    $files = generateDependentRequiredSchemas([
        'Pet' => [
            'type' => 'object',
            'required' => ['petType'],
            'properties' => ['petType' => ['type' => 'string']],
            'discriminator' => [
                'propertyName' => 'petType',
                'mapping' => ['cat' => '#/components/schemas/Cat'],
            ],
        ],
        'Cat' => [
            'allOf' => [
                ['$ref' => '#/components/schemas/Pet'],
                [
                    'type' => 'object',
                    'properties' => [
                        'huntingMode' => ['type' => 'string'],
                        'preferredPrey' => ['type' => 'string'],
                    ],
                    'dependentRequired' => ['huntingMode' => ['preferredPrey']],
                ],
            ],
        ],
    ]);

    expect($files['CatData']->code)
        ->toContain("'preferredPrey' => ['required_with:huntingMode', 'string'],");
});

it('skips a tautological self-dependency instead of rejecting present-but-empty values', function () {
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['creditCard']],
        ],
    ]);

    expect($files['PaymentData']->code)
        ->toContain("'creditCard' => ['sometimes', 'string'],")
        ->not->toContain('required_with');
});

it('skips a trigger whose name contains a comma and reports it through the warnings channel', function () {
    $generator = new ModelGenerator;
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'a,b' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
            ],
            'dependentRequired' => ['a,b' => ['billingAddress']],
        ],
    ], $generator);

    // required_with:a,b would watch two fields "a" and "b", not the literal
    // "a,b" key, so the unenforceable dependency is dropped loudly.
    expect($files['PaymentData']->code)->not->toContain('required_with');
    expect($generator->warnings())->toContain(
        'Schema "Payment": dependentRequired trigger "a,b" contains a comma, which cannot be expressed in a Laravel required_with parameter list; the dependency of "billingAddress" on it is not enforced.',
    );
});

it('ignores a malformed dependentRequired value instead of crashing or inventing rules', function () {
    $files = generateDependentRequiredSchemas([
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
            ],
            // Hostile/malformed shapes: a non-array dependents value, a
            // non-string dependent, an empty dependent name.
            'dependentRequired' => [
                'creditCard' => 'billingAddress',
                'paypal' => [42, ''],
            ],
        ],
    ]);

    expect($files['PaymentData']->code)->not->toContain('required_with');
});

it('is deterministic: regenerating dependentRequired rules produces byte-identical output', function () {
    $schemas = [
        'Payment' => [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'paypal' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
                'cvv' => ['type' => 'string'],
            ],
            'dependentRequired' => [
                'creditCard' => ['billingAddress', 'cvv'],
                'paypal' => ['billingAddress'],
            ],
        ],
    ];

    $first = generateDependentRequiredSchemas($schemas);
    $second = generateDependentRequiredSchemas($schemas);

    foreach ($first as $name => $file) {
        expect($second[$name]->code)->toBe($file->code);
    }
});
