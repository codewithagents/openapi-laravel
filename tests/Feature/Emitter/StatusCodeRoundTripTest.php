<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * End-to-end for spec-declared response status codes (issue #64): generate the
 * full scaffold from a spec with 201 and 204 success responses, load the
 * emitted classes (including the inlined RespondsWithStatus middleware) into
 * the booted Laravel app, register the GENERATED routes file, implement the
 * abstract controllers with plain returns, and drive real HTTP requests.
 *
 * Proves the acceptance criteria of #64 without hand-written status glue: a
 * 201 create operation answers 201 with the serialized Data body, a 204 void
 * operation answers 204 with an empty body, a 200 operation stays 200, and an
 * error response (422 from the spec-derived rules) is never promoted.
 */
beforeEach(function () {
    static $routesPath = null;

    if ($routesPath === null) {
        $dir = sys_get_temp_dir().'/oal_status_roundtrip_'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $document = (new SpecParser)->parseFile(__DIR__.'/../../Fixtures/server/petstore.yaml');
        $generator = new ModelGenerator;
        $modelFiles = $generator->generate($document);
        $options = new ServerOptions;
        $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($document);
        $controllers = (new ControllerGenerator($options))->generate($descriptors);
        $routes = (new RouteGenerator($options))->generate($descriptors);

        // Write and load each generated class once, skipping any already
        // declared in this process (the query round-trip suite loads the same
        // petstore Data classes; the generator is deterministic, so the
        // definitions are byte-identical). The support and controller files
        // declare no `directory`, so they land in per-purpose subdirectories
        // here only for tidiness; loadGeneratedFiles resolves each FQCN from
        // the file's own namespace declaration.
        loadGeneratedFiles($dir, [...array_values($modelFiles), ...array_values($generator->queryFiles())]);
        loadGeneratedFiles($dir.'/Support', array_values($generator->supportFiles()));
        loadGeneratedFiles($dir.'/Controllers', array_values($controllers));

        // The hand-written concrete controllers: plain returns only, no
        // response() helpers, no status codes. The whole point of #64 is that
        // the generated scaffold produces the spec statuses on its own.
        $concrete = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Http\Controllers\Api;

        use App\Data\Pet\ListPetsQueryData;
        use App\Data\Pet\PetData;
        use App\Data\Pet\PetWritableData;
        use Illuminate\Http\JsonResponse;
        use Spatie\LaravelData\DataCollection;

        final class PetController extends AbstractPetController
        {
            public function index(ListPetsQueryData $query): DataCollection
            {
                return PetData::collect([['id' => 1, 'name' => 'Rex']], DataCollection::class);
            }

            public function store(PetWritableData $pet): PetData
            {
                return PetData::from(['id' => 7, 'name' => $pet->name]);
            }

            public function show(int $petId): PetData
            {
                return PetData::from(['id' => $petId, 'name' => 'Rex']);
            }

            public function destroy(int $petId): void
            {
                // Nothing to return: the generated route enforces the 204.
            }
        }

        final class UntaggedController extends AbstractUntaggedController
        {
            public function index(): JsonResponse
            {
                return new JsonResponse(['ok' => true]);
            }
        }
        PHP;
        file_put_contents($dir.'/ConcreteControllers.php', $concrete);
        require_once $dir.'/ConcreteControllers.php';

        $routesPath = $dir.'/'.$routes->filename();
        file_put_contents($routesPath, $routes->code);
    }

    // Register the generated route table on every test's fresh app, exactly
    // as a host app would (require, not require_once: routes re-register).
    require $routesPath;
});

it('answers a 201 create operation with 201 and the serialized Data body', function () {
    $response = $this->postJson('/pets', ['name' => 'Bella']);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Bella')
        ->assertJsonPath('id', 7);
});

it('never promotes an error response on a 201 route: the 422 stays a 422', function () {
    // Missing the required name: the 422 comes from the spec-derived rules,
    // and the status middleware must leave any non-200 response untouched.
    $this->postJson('/pets', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('answers a 204 void operation with 204 and an empty body', function () {
    $response = $this->deleteJson('/pets/7');

    $response->assertNoContent();
    expect($response->getContent())->toBe('');
});

it('leaves 200 operations untouched', function () {
    $this->getJson('/pets/7')
        ->assertOk()
        ->assertJsonPath('id', 7);

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('ok', true);
});
