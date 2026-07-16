<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * End-to-end for the ApiError carrier and the generated `<Operation>Errors`
 * factories: generate the full scaffold from api-error.yaml, load the emitted
 * classes (Data classes, the inlined ApiError support class, the factory
 * classes, the abstract controller) into the booted app, register the GENERATED
 * routes, implement the abstract controller with plain returns plus error
 * throws, and drive real HTTP requests through the REAL Laravel exception
 * handler with zero bootstrap/app.php registration.
 *
 * Proves the point of the whole feature: a concrete method typed to return the
 * success DTO answers a spec-declared error by THROWING (never a `return`), so
 * the declared return type stays satisfied, and the thrown ApiError renders the
 * exact spec error body at the exact status. Both the generated factory
 * (`GetPetByIdErrors::notFound(...)`) and the direct carrier
 * (`ApiError::notFound(...)`) paths, a shared-schema factory (badRequest AND
 * notFound both forwarding to PetErrorData), and the untouched success path.
 */
beforeEach(function () {
    static $routesPath = null;

    if ($routesPath === null) {
        $dir = sys_get_temp_dir().'/oal_apierror_roundtrip_'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $document = (new SpecParser)->parseFileToDocument(__DIR__.'/../../Fixtures/server/api-error.yaml');
        $generator = new ModelGenerator;
        $modelFiles = $generator->generate($document);
        $options = new ServerOptions;
        $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($document);
        $controllers = (new ControllerGenerator($options))->generate($descriptors);
        $routes = (new RouteGenerator($options))->generate($descriptors);

        loadGeneratedFiles($dir, [
            ...array_values($modelFiles),
            ...array_values($generator->pathFiles()),
            ...array_values($generator->errorFactoryFiles()),
        ]);
        loadGeneratedFiles($dir.'/Support', array_values($generator->supportFiles()));
        loadGeneratedFiles($dir.'/Controllers', array_values($controllers));

        $concrete = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Http\Controllers\Api;

        use App\Data\Pets\GetPetByIdErrors;
        use App\Data\Pets\PetData;
        use App\Data\Pets\PetErrorData;
        use App\Data\Pets\UpdatePetErrors;
        use App\Data\Support\ApiError;

        final class PetsController extends AbstractPetsController
        {
            public function store(PetData $pet): PetData
            {
                return PetData::from(['id' => 7, 'name' => $pet->name]);
            }

            public function show(int $petId): PetData
            {
                // The generated factory: one call, no manual status, no manual wrapper.
                if ($petId === 999) {
                    throw GetPetByIdErrors::notFound(message: 'No pet 999.');
                }

                // The ApiError escape hatch: build the Data class by hand and throw it.
                if ($petId === 998) {
                    throw ApiError::notFound(PetErrorData::from(['message' => 'No pet 998.']));
                }

                return PetData::from(['id' => $petId, 'name' => 'Rex']);
            }

            public function update(PetData $pet, int $petId): PetData
            {
                // Shared schema across two statuses: both forward to PetErrorData.
                if ($petId === 400) {
                    throw UpdatePetErrors::badRequest(message: 'Bad update.');
                }
                if ($petId === 999) {
                    throw UpdatePetErrors::notFound(message: 'No pet.');
                }

                return PetData::from(['id' => $petId, 'name' => $pet->name]);
            }
        }
        PHP;
        file_put_contents($dir.'/ConcreteControllers.php', $concrete);
        require_once $dir.'/ConcreteControllers.php';

        $routesPath = $dir.'/'.$routes->filename();
        file_put_contents($routesPath, $routes->code);
    }

    require $routesPath;
});

it('answers a spec error thrown through the generated factory with the exact schema body at 404', function () {
    // The declared return type stays PetData throughout: the 404 branch throws,
    // never returns, so Laravel's real exception handler renders the ApiError.
    $this->getJson('/pets/999')
        ->assertStatus(404)
        ->assertExactJson(['message' => 'No pet 999.']);
});

it('answers a spec error thrown through the ApiError escape hatch too', function () {
    $this->getJson('/pets/998')
        ->assertStatus(404)
        ->assertExactJson(['message' => 'No pet 998.']);
});

it('leaves the success path completely unaffected', function () {
    $this->getJson('/pets/7')
        ->assertOk()
        ->assertJsonPath('id', 7)
        ->assertJsonPath('name', 'Rex');
});

it('round-trips both statuses of a shared-schema factory (badRequest and notFound to one Data class)', function () {
    $this->putJson('/pets/400', ['id' => 400, 'name' => 'X'])
        ->assertStatus(400)
        ->assertExactJson(['message' => 'Bad update.']);

    $this->putJson('/pets/999', ['id' => 999, 'name' => 'X'])
        ->assertStatus(404)
        ->assertExactJson(['message' => 'No pet.']);
});

it('does not touch the declared success status of a create operation (201)', function () {
    $this->postJson('/pets', ['id' => 1, 'name' => 'Bella'])
        ->assertStatus(201)
        ->assertJsonPath('id', 7)
        ->assertJsonPath('name', 'Bella');
});
