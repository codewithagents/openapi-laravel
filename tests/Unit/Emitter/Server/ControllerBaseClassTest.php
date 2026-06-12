<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Configurable controller base class (issue #83). By default a generated
 * abstract controller extends nothing (framework-light by design); when
 * controllers.base_class names an FQCN, every abstract extends it. The base is
 * referenced by short name with an import (the exact form Pint's
 * fully_qualified_strict_types fixer produces), the import is skipped when the
 * base already lives in the controller namespace, and a short-name collision
 * fails loudly instead of writing a PHP fatal.
 *
 * @return array<string, GeneratedFile>
 */
function generateControllersWithBase(?string $baseClass): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);
    $options = new ServerOptions(controllerBaseClass: $baseClass);

    $descriptors = (new OperationCollector($options, $generator->registry()))->collect($doc);

    return (new ControllerGenerator($options))->generate($descriptors);
}

it('emits no extends clause and no base import by default', function () {
    $code = generateControllersWithBase(null)['AbstractPetController']->code;

    expect($code)->toContain("abstract class AbstractPetController\n")
        ->and($code)->not->toContain('extends');
});

it('extends the configured base class via short name plus import', function () {
    $code = generateControllersWithBase('App\\Http\\Controllers\\Controller')['AbstractPetController']->code;

    expect($code)->toContain('use App\Http\Controllers\Controller;')
        ->and($code)->toContain('abstract class AbstractPetController extends Controller');
});

it('applies the base class to every generated controller', function () {
    $files = generateControllersWithBase('App\\Http\\Controllers\\Controller');

    foreach ($files as $file) {
        expect($file->code)->toContain(' extends Controller');
    }
});

it('keeps the import block sorted with the base class import included', function () {
    $code = generateControllersWithBase('Zzz\\Base\\ZController')['AbstractPetController']->code;

    $petData = strpos($code, 'use App\Data\PetData;');
    $base = strpos($code, 'use Zzz\Base\ZController;');

    expect($petData)->toBeLessThan($base)
        ->and($code)->toContain('abstract class AbstractPetController extends ZController');
});

it('accepts a leading backslash and normalizes it away', function () {
    $code = generateControllersWithBase('\\App\\Http\\Controllers\\Controller')['AbstractPetController']->code;

    expect($code)->toContain('use App\Http\Controllers\Controller;')
        ->and($code)->toContain('extends Controller')
        ->and($code)->not->toContain('extends \\');
});

it('skips the import when the base class lives in the controller namespace', function () {
    $code = generateControllersWithBase('App\\Http\\Controllers\\Api\\BaseApiController')['AbstractPetController']->code;

    expect($code)->toContain('abstract class AbstractPetController extends BaseApiController')
        ->and($code)->not->toContain('use App\Http\Controllers\Api\BaseApiController;');
});

it('imports a global-namespace base class by its bare name', function () {
    $code = generateControllersWithBase('Controller')['AbstractPetController']->code;

    expect($code)->toContain('use Controller;')
        ->and($code)->toContain('abstract class AbstractPetController extends Controller');
});

it('fails loudly when the base short name collides with the abstract class itself', function () {
    generateControllersWithBase('App\\Base\\AbstractPetController');
})->throws(GenerationException::class, 'same short name as the generated abstract controller');

it('fails loudly when the base short name collides with an imported Data class', function () {
    // The petstore controller imports App\Data\PetData; a base class whose
    // short name is also PetData would emit two conflicting use statements.
    generateControllersWithBase('App\\Base\\PetData');
})->throws(GenerationException::class, 'short name collides with the import');

it('is deterministic: same spec and base in, byte-identical controllers out', function () {
    $first = array_map(fn (GeneratedFile $f): string => $f->code, generateControllersWithBase('App\\Http\\Controllers\\Controller'));
    $second = array_map(fn (GeneratedFile $f): string => $f->code, generateControllersWithBase('App\\Http\\Controllers\\Controller'));

    expect($first)->toBe($second);
});
