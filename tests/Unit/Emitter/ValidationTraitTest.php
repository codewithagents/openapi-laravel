<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\OpenApiDocument;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Validation extension trait (issue #83). Generated Data classes are
 * overwritten on every regenerate, so users must never edit them; when
 * output.validation_trait names a user-owned trait, every generated Data
 * class (models, discriminated union bases and variants, query classes)
 * carries `use <Trait>;`, the sanctioned home for laravel-data's static
 * messages() / attributes() validation hooks. The behavioral proof that the
 * hooks reach the real Laravel Validator lives in
 * tests/Feature/Emitter/ValidationMessagesRoundTripTest.php.
 *
 * @param  array<string, mixed>  $schemas
 * @return array<string, GeneratedFile>
 */
function generateWithTrait(array $schemas, ?string $trait): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => $schemas],
    ];

    $spec = (new OpenApiReader)->read($document);
    expect($spec)->toBeInstanceOf(OpenApiDocument::class);

    return (new ModelGenerator(new GeneratorOptions(validationTrait: $trait)))->generate($spec);
}

it('emits no trait line by default', function () {
    $files = generateWithTrait([
        'Customer' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], null);

    expect($files['CustomerData']->code)->not->toContain('use ApiMessages;');
});

it('adds the trait import and use line to a generated Data class', function () {
    $files = generateWithTrait([
        'Customer' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], 'App\\Support\\ApiMessages');

    expect($files['CustomerData']->code)->toContain('use App\Support\ApiMessages;')
        ->and($files['CustomerData']->code)->toContain("{\n    use ApiMessages;\n\n    public function __construct(");
});

it('accepts a leading backslash and skips the import for a trait in the Data namespace', function () {
    $files = generateWithTrait([
        'Customer' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], '\\App\\Data\\ApiMessages');

    $code = $files['CustomerData']->code;

    expect($code)->toContain("{\n    use ApiMessages;\n")
        ->and($code)->not->toContain('use App\Data\ApiMessages;');
});

it('places the trait line before the marker comment in an empty Data class (#95)', function () {
    $files = generateWithTrait([
        'Blank' => ['type' => 'object'],
    ], 'App\\Support\\ApiMessages');

    expect($files['BlankData']->code)
        ->toContain("{\n    use ApiMessages;\n\n    // The spec defines no properties for this schema.\n}");
});

it('places the trait line before rules() in a property-less closed object', function () {
    $files = generateWithTrait([
        'Closed' => ['type' => 'object', 'additionalProperties' => false],
    ], 'App\\Support\\ApiMessages');

    expect($files['ClosedData']->code)
        ->toContain("{\n    use ApiMessages;\n\n    /**")
        ->and($files['ClosedData']->code)->toContain('public static function rules(): array');
});

it('adds the trait to a discriminated union base and its variants', function () {
    $files = generateWithTrait([
        'Pet' => [
            'oneOf' => [
                ['$ref' => '#/components/schemas/Cat'],
                ['$ref' => '#/components/schemas/Dog'],
            ],
            'discriminator' => [
                'propertyName' => 'petType',
                'mapping' => ['cat' => 'Cat', 'dog' => 'Dog'],
            ],
        ],
        'Cat' => [
            'type' => 'object',
            'required' => ['petType', 'meow'],
            'properties' => ['petType' => ['type' => 'string'], 'meow' => ['type' => 'string']],
        ],
        'Dog' => [
            'type' => 'object',
            'required' => ['petType', 'bark'],
            'properties' => ['petType' => ['type' => 'string'], 'bark' => ['type' => 'string']],
        ],
    ], 'App\\Support\\ApiMessages');

    foreach (['PetData', 'CatData', 'DogData'] as $class) {
        expect($files[$class]->code)->toContain('use App\Support\ApiMessages;')
            ->and($files[$class]->code)->toContain("    use ApiMessages;\n\n");
    }
});

it('adds the trait to per-operation query Data classes (#63)', function () {
    $parser104 = new SpecParser;
    $doc = $parser104->parseFileToDocument(__DIR__.'/../../Fixtures/server/query-parameters.yaml');
    $docCebe = $parser104->buildCebeModel($doc, __DIR__.'/../../Fixtures/server/query-parameters.yaml');
    $generator = new ModelGenerator(new GeneratorOptions(validationTrait: 'App\\Support\\ApiMessages'));
    $generator->generate($doc);
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($docCebe);

    $queryFiles = $generator->queryFiles();

    expect($queryFiles)->not->toBe([]);
    foreach ($queryFiles as $file) {
        expect($file->code)->toContain('use App\Support\ApiMessages;')
            ->and($file->code)->toContain("    use ApiMessages;\n\n");
    }
});

it('fails loudly when the trait short name collides with an import', function () {
    // Every generated Data class imports Spatie\LaravelData\Data; a trait whose
    // short name is also Data would emit two conflicting use statements.
    generateWithTrait([
        'Customer' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], 'App\\Support\\Data');
})->throws(GenerationException::class, 'short name collides with the import');

it('fails loudly when the trait short name collides with the generated class itself', function () {
    generateWithTrait([
        'Customer' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ], 'App\\Support\\CustomerData');
})->throws(GenerationException::class, 'same short name as the generated class');

it('is deterministic: same spec and trait in, byte-identical files out', function () {
    $schemas = ['Customer' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]];

    $first = array_map(fn (GeneratedFile $f): string => $f->code, generateWithTrait($schemas, 'App\\Support\\ApiMessages'));
    $second = array_map(fn (GeneratedFile $f): string => $f->code, generateWithTrait($schemas, 'App\\Support\\ApiMessages'));

    expect($first)->toBe($second);
});

it('keeps trait-bearing output Pint-idempotent (the drift-gate guarantee, #60)', function () {
    // The three body shapes the trait line lands in: a normal constructor
    // class, an empty marker class (#95), and a rules-only closed object.
    $files = generateWithTrait([
        'Customer' => [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'integer', 'minimum' => 0]],
        ],
        'Blank' => ['type' => 'object'],
        'Closed' => ['type' => 'object', 'additionalProperties' => false],
    ], 'App\\Support\\ApiMessages');

    $dir = sys_get_temp_dir().'/oal_trait_pint_'.bin2hex(random_bytes(6));
    expect(mkdir($dir, 0700, true))->toBeTrue();

    foreach ($files as $file) {
        file_put_contents($dir.'/'.$file->filename(), $file->code);
    }

    $repoRoot = dirname(__DIR__, 3);

    try {
        $command = escapeshellarg($repoRoot.'/vendor/bin/pint').' --test --config='.escapeshellarg($repoRoot.'/pint.json').' '.escapeshellarg($dir).' 2>&1';
        exec($command, $output, $exitCode);
    } finally {
        foreach ($files as $file) {
            @unlink($dir.'/'.$file->filename());
        }
        @rmdir($dir);
    }

    expect($exitCode)->toBe(0, "Pint would reformat trait-bearing output (not idempotent):\n".implode("\n", $output));
});
