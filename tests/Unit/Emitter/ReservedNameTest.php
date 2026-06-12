<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;

/**
 * Regression for #21: a component schema literally named `Data` must not produce
 * `final class Data extends Data`, which `use Spatie\LaravelData\Data;` turns
 * into a fatal self-redeclaration ("Cannot redeclare class App\Data\Data"). The
 * emitter pre-reserves the framework short-names it imports, so the schema is
 * routed onto the suffix path (`Data` -> `Data_2`) and the base class stays
 * unshadowed. Seen in the wild on bbc.json and here_tracking.json.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateReservedNameSchemas(array $schemas): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return (new ModelGenerator)->generate($spec);
}

it('renames a schema named Data and keeps it extending the imported base (#21)', function () {
    $files = generateReservedNameSchemas([
        'Data' => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
            ],
        ],
    ]);

    // The class is NOT named `Data` (it took the suffix path), so it cannot
    // redeclare the imported base class.
    expect($files)->not->toHaveKey('Data')
        ->and($files)->toHaveKey('Data_2');

    $code = $files['Data_2']->code;

    // It still imports and extends the Spatie base class `Data`.
    expect($code)->toContain('use Spatie\LaravelData\Data;')
        ->and($code)->toContain('final class Data_2 extends Data')
        ->and($code)->not->toContain('class Data extends Data');

    // And it compiles: `php -l`, not just token_get_all, which would accept the
    // pre-fix self-redeclaration.
    expect(phpLintFailures($files, 'schema-named-Data'))->toBe([]);
});

it('renames an enum schema named Data away from the imported base name (#21)', function () {
    $files = generateReservedNameSchemas([
        'Data' => ['type' => 'string', 'enum' => ['a', 'b']],
    ]);

    expect($files)->not->toHaveKey('Data')
        ->and($files)->toHaveKey('Data_2');

    expect($files['Data_2']->code)->toContain('enum Data_2: string');
    expect(phpLintFailures($files, 'enum-named-Data'))->toBe([]);
});
