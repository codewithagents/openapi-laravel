<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Parser\Spec\OperationNode;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SecurityRequirementNode;

/**
 * #104 T1: SecurityRequirementNode replaces the getSerializableData() escape
 * in SecurityMiddlewareResolver: the dynamic scheme-name keys become a plain
 * typed map. The three-way presence semantics issue #77 depends on (absent vs
 * explicit empty vs declared) live on the operation/document `security`
 * property.
 */
it('exposes the dynamic scheme map and its names', function () {
    $requirement = new SecurityRequirementNode([
        'petstore_auth' => ['write:pets', 'read:pets'],
        'api_key' => [],
    ]);

    expect($requirement->schemes)->toBe(['petstore_auth' => ['write:pets', 'read:pets'], 'api_key' => []])
        ->and($requirement->schemeNames())->toBe(['petstore_auth', 'api_key']);
});

it('models the empty requirement object', function () {
    $requirement = new SecurityRequirementNode;

    expect($requirement->schemes)->toBe([])
        ->and($requirement->schemeNames())->toBe([]);
});

it('distinguishes absent security from the explicit public override on an operation', function () {
    $absent = new OperationNode(operationId: 'a');
    $publicOverride = new OperationNode(operationId: 'b', security: []);
    $declared = new OperationNode(operationId: 'c', security: [new SecurityRequirementNode(['api_key' => []])]);

    expect($absent->security)->toBeNull()
        ->and($publicOverride->security)->toBe([])
        ->and($declared->security)->toHaveCount(1);
});
