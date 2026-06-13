<?php

declare(strict_types=1);

use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\RouteGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * Regression for issue #125: a spec-declared non-200 (and non-201) success
 * status on a mutating operation that RETURNS a Data object must be honored.
 *
 * spatie/laravel-data serializes a Data object returned from a POST as 201
 * Created, not 200, so the #64 status-enforcing middleware (which used to
 * rewrite only an exactly-200 response) silently dropped the declared 202 and
 * left the response at 201. This drives the full pipeline end-to-end: generate
 * the scaffold from a 202-returning-Data spec, load the inlined
 * RespondsWithStatus middleware, register the GENERATED routes, implement the
 * controller with a plain Data return (no status glue), and assert the real
 * HTTP response is 202 with the serialized body, while the 200 GET stays 200.
 */
beforeEach(function () {
    static $routesPath = null;

    if ($routesPath === null) {
        $dir = sys_get_temp_dir().'/oal_accepted_roundtrip_'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $parser = new SpecParser;
        $document = $parser->parseFileToDocument(__DIR__.'/../../Fixtures/server/accepted-status.yaml');
        $generator = new ModelGenerator;
        $modelFiles = $generator->generate($document);
        $options = new ServerOptions;
        $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($document);
        $controllers = (new ControllerGenerator($options))->generate($descriptors);
        $routes = (new RouteGenerator($options))->generate($descriptors);

        loadGeneratedFiles($dir, [...array_values($modelFiles), ...array_values($generator->queryFiles())]);
        loadGeneratedFiles($dir.'/Support', array_values($generator->supportFiles()));
        loadGeneratedFiles($dir.'/Controllers', array_values($controllers));

        // Plain Data returns, no response() helpers and no status codes: the
        // generated scaffold must produce the spec statuses on its own.
        $concrete = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Http\Controllers\Api;

        use App\Data\Job\JobData;
        use App\Data\Job\JobWritableData;

        final class JobController extends AbstractJobController
        {
            public function store(JobWritableData $job): JobData
            {
                return JobData::from(['id' => 7, 'name' => $job->name]);
            }

            public function show(int $jobId): JobData
            {
                return JobData::from(['id' => $jobId, 'name' => 'Backfill']);
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

it('answers a 202-returning-Data POST with 202, not the laravel-data 201 default (issue #125)', function () {
    $response = $this->postJson('/jobs', ['name' => 'Reindex']);

    $response->assertStatus(202)
        ->assertJsonPath('name', 'Reindex')
        ->assertJsonPath('id', 7);
});

it('still rejects an invalid body with 422, never promoting it to 202', function () {
    $this->postJson('/jobs', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('leaves a 200 GET untouched', function () {
    $this->getJson('/jobs/7')
        ->assertOk()
        ->assertJsonPath('id', 7);
});
