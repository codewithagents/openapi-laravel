<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;

/**
 * The read/write variant split is TRANSITIVE: a writable variant is synthesized
 * for a schema if it OR any descendant (nested object, collection element, map
 * value, allOf member, union member) declares a `readOnly`/`writeOnly` property,
 * and the write variant recurses into the nested/collection-nested Data classes
 * so a client-sent value for a nested readOnly field is dropped on the write
 * path at any depth.
 *
 * Before the fix, a root schema with no own read/write flags never got a write
 * variant, so a request body bound to the READ variant whose nested item classes
 * treated nested `readOnly` as writable.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateNestedSplitSchemas(array $schemas): array
{
    $document = (new OpenApiReader)->read([
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [],
        'components' => ['schemas' => $schemas],
    ]);

    return (new ModelGenerator)->generate($document);
}

it('still ignores a top-level readOnly field on the write variant (no regression)', function () {
    $files = generateNestedSplitSchemas([
        'Account' => [
            'type' => 'object',
            'required' => ['id', 'username'],
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'username' => ['type' => 'string'],
            ],
        ],
    ]);

    expect(array_keys($files))->toBe(['AccountData', 'AccountWritableData']);

    $write = $files['AccountWritableData']->code;
    expect($write)->toContain('$username')
        ->and($write)->not->toContain('$id');

    $read = $files['AccountData']->code;
    expect($read)->toContain('$id')
        ->and($read)->toContain('$username');
});

it('synthesizes a writable variant transitively when only a NESTED object marks readOnly', function () {
    $files = generateNestedSplitSchemas([
        'Order' => [
            'type' => 'object',
            'properties' => [
                'note' => ['type' => 'string'],
                'customer' => ['$ref' => '#/components/schemas/Customer'],
            ],
        ],
        'Customer' => [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string'],
            ],
        ],
    ]);

    // Both the root (transitively) and the nested component split.
    expect(array_keys($files))->toContain('OrderWritableData')
        ->and(array_keys($files))->toContain('CustomerWritableData');

    // The root write variant references the nested WRITE variant, so the nested
    // readOnly field is dropped on the write path.
    $orderWrite = $files['OrderWritableData']->code;
    expect($orderWrite)->toContain('CustomerWritableData');

    $customerWrite = $files['CustomerWritableData']->code;
    expect($customerWrite)->toContain('$name')
        ->and($customerWrite)->not->toContain('$id');

    // The read path keeps the nested readOnly field on the read variant.
    $customerRead = $files['CustomerData']->code;
    expect($customerRead)->toContain('$id')
        ->and($customerRead)->toContain('$name');
});

it('drops a readOnly field nested inside an inline object on the write path', function () {
    $files = generateNestedSplitSchemas([
        'Wrapper' => [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string'],
                'inner' => [
                    'type' => 'object',
                    'properties' => [
                        'serverId' => ['type' => 'integer', 'readOnly' => true],
                        'value' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ]);

    expect(array_keys($files))->toContain('WrapperWritableData');

    // The write variant recurses into its inline nested object, which is spawned
    // under the write holder's name hint (WrapperWritable + Inner), dropping the
    // readOnly field; the read variant's inline nested object keeps it.
    $writeInner = $files['WrapperWritableInnerData']->code ?? '';
    expect($writeInner)->not->toBe('')
        ->and($writeInner)->toContain('$value')
        ->and($writeInner)->not->toContain('$serverId');

    $readInner = $files['WrapperInnerData']->code ?? '';
    expect($readInner)->toContain('$serverId')
        ->and($readInner)->toContain('$value');
});

it('drops a readOnly field nested inside a COLLECTION element on the write path', function () {
    $files = generateNestedSplitSchemas([
        'Cart' => [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/LineItem'],
                ],
            ],
        ],
        'LineItem' => [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
                'computedTotal' => ['type' => 'number', 'readOnly' => true],
            ],
        ],
    ]);

    // The collection holder splits transitively, and the element component splits.
    expect(array_keys($files))->toContain('CartWritableData')
        ->and(array_keys($files))->toContain('LineItemWritableData');

    // The collection holder's write variant collects the element WRITE variant.
    $cartWrite = $files['CartWritableData']->code;
    expect($cartWrite)->toContain('LineItemWritableData');

    $itemWrite = $files['LineItemWritableData']->code;
    expect($itemWrite)->toContain('$sku')
        ->and($itemWrite)->not->toContain('$computedTotal');

    $itemRead = $files['LineItemData']->code;
    expect($itemRead)->toContain('$computedTotal');
});

it('synthesizes no writable variant when nothing declares readOnly/writeOnly at any depth', function () {
    $files = generateNestedSplitSchemas([
        'Outer' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'inner' => ['$ref' => '#/components/schemas/Inner'],
                'tags' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/Inner'],
                ],
            ],
        ],
        'Inner' => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
            ],
        ],
    ]);

    $classes = array_keys($files);
    expect($classes)->not->toContain('OuterWritableData')
        ->and($classes)->not->toContain('InnerWritableData')
        ->and($classes)->toContain('OuterData')
        ->and($classes)->toContain('InnerData');
});
