<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\SpecParser;

/**
 * @return list<OperationDescriptor>
 */
function collectPetstore(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    return (new OperationCollector(new ServerOptions, $generator->registry()))->collect($doc);
}

/**
 * @return list<OperationDescriptor>
 */
function collectPathParameters(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/path-parameters.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    return (new OperationCollector(new ServerOptions, $generator->registry()))->collect($doc);
}

/**
 * @param  list<OperationDescriptor>  $descriptors
 */
function descriptorFor(array $descriptors, string $method, string $path): OperationDescriptor
{
    foreach ($descriptors as $descriptor) {
        if ($descriptor->httpMethod === $method && $descriptor->path === $path) {
            return $descriptor;
        }
    }

    throw new RuntimeException("No descriptor for {$method} {$path}");
}

it('groups operations under a controller derived from the first tag', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets');

    expect($get->controllerClass)->toBe('PetController')
        ->and($get->abstractClass)->toBe('AbstractPetController');
});

it('falls back to an Untagged controller when an operation has no tag', function () {
    $health = descriptorFor(collectPetstore(), 'get', '/health');

    // "Untagged" is not reserved, so it stays readable (unlike "Default").
    expect($health->controllerClass)->toBe('UntaggedController')
        ->and($health->abstractClass)->toBe('AbstractUntaggedController');
});

it('uses the operationId for the method name when present', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets/{petId}');

    expect($get->methodName)->toBe('getPetById');
});

it('derives a deterministic method name when operationId is absent', function () {
    $health = descriptorFor(collectPetstore(), 'get', '/health');

    expect($health->methodName)->toBe('getHealth');
});

it('types integer path params as int and others as string', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets/{petId}');

    expect($get->pathParams)->toBe([['name' => 'petId', 'phpType' => 'int']]);
});

it('types a PathItem-level integer path param as int (issue #66)', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/users/{userId}');

    expect($get->pathParams)->toBe([['name' => 'userId', 'phpType' => 'int']]);
});

it('lets an operation-level parameter override a PathItem-level one with the same name and location', function () {
    // The path level declares userId as integer, the put operation redeclares
    // it as string: per OpenAPI precedence the operation wins.
    $put = descriptorFor(collectPathParameters(), 'put', '/users/{userId}');

    expect($put->pathParams)->toBe([['name' => 'userId', 'phpType' => 'string']]);
});

it('resolves a PathItem-level $ref parameter into components.parameters', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/orders/{orderId}');

    expect($get->pathParams)->toBe([['name' => 'orderId', 'phpType' => 'int']]);
});

it('resolves an operation-level $ref parameter into components.parameters', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/reports/{reportId}');

    expect($get->pathParams)->toBe([['name' => 'reportId', 'phpType' => 'int']]);
});

it('degrades an unresolvable parameter $ref to the string fallback without fataling', function () {
    $get = descriptorFor(collectPathParameters(), 'get', '/legacy/{legacyId}');

    expect($get->pathParams)->toBe([['name' => 'legacyId', 'phpType' => 'string']]);
});

it('types a $ref request body against the writable variant class', function () {
    $post = descriptorFor(collectPetstore(), 'post', '/pets');

    expect($post->bodyParam)->toBe(['name' => 'pet', 'type' => 'PetWritableData'])
        ->and($post->bodyRequiresRequest)->toBeFalse()
        ->and($post->imports)->toContain('App\\Data\\PetWritableData');
});

it('returns the Data class for a $ref response', function () {
    $get = descriptorFor(collectPetstore(), 'get', '/pets/{petId}');

    expect($get->returnType)->toBe('PetData')
        ->and($get->returnDoc)->toBeNull();
});

it('returns a DataCollection for an array-of-ref response', function () {
    $list = descriptorFor(collectPetstore(), 'get', '/pets');

    // Short return type matches the use import so the import is never unused.
    expect($list->returnType)->toBe('DataCollection')
        ->and($list->returnDoc)->toBe('DataCollection<int, PetData>')
        ->and($list->imports)->toContain('Spatie\\LaravelData\\DataCollection')
        ->and($list->imports)->toContain('App\\Data\\PetData');
});

it('returns void for a selected 204 response (issue #64)', function () {
    $delete = descriptorFor(collectPetstore(), 'delete', '/pets/{petId}');

    // A 204 carries no body (RFC 9110), so there is nothing to return: the
    // abstract method is void and the generated route middleware sets the
    // status and guarantees the empty response.
    expect($delete->returnType)->toBe('void')
        ->and($delete->successStatus)->toBe(204)
        ->and($delete->needsStatusMiddleware())->toBeTrue()
        ->and($delete->imports)->not->toContain('Illuminate\\Http\\JsonResponse');
});

it('records the selected success status alongside the return type (issue #64)', function () {
    $create = descriptorFor(collectPetstore(), 'post', '/pets');
    $get = descriptorFor(collectPetstore(), 'get', '/pets/{petId}');

    expect($create->successStatus)->toBe(201)
        ->and($create->needsStatusMiddleware())->toBeTrue()
        ->and($create->returnType)->toBe('PetData')
        ->and($get->successStatus)->toBe(200)
        ->and($get->needsStatusMiddleware())->toBeFalse();
});

it('marks the RespondsWithStatus support class for inlining when a non-200 status exists (issue #64)', function () {
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    // Wired like the planner: the collector records the middleware class on
    // the model generator, so supportFiles() inlines it into the consumer's
    // own Support namespace next to the rule classes (issue #40 mechanism).
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($doc);

    $support = $generator->supportFiles();
    expect($support)->toHaveKey('RespondsWithStatus')
        ->and($support['RespondsWithStatus']->code)->toContain('namespace App\Data\Support;')
        ->and($support['RespondsWithStatus']->code)->toContain('final class RespondsWithStatus');
});

it('does not inline RespondsWithStatus when every success response is a 200 (issue #64)', function () {
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => [
            '/things' => [
                'get' => [
                    'operationId' => 'listThings',
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ],
        ],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    (new OperationCollector(new ServerOptions, $generator->registry(), null, $generator))->collect($spec);

    expect($generator->supportFiles())->not->toHaveKey('RespondsWithStatus');
});

it('orders descriptors by path then by a fixed HTTP-method order', function () {
    $descriptors = collectPetstore();

    $keys = array_map(fn (OperationDescriptor $d): string => $d->httpMethod.' '.$d->path, $descriptors);

    expect($keys)->toBe([
        'get /health',
        'get /pets',
        'post /pets',
        'get /pets/{petId}',
        'delete /pets/{petId}',
    ]);
});

it('is deterministic: same spec in, identical descriptors out', function () {
    $first = array_map(fn (OperationDescriptor $d): string => $d->methodName, collectPetstore());
    $second = array_map(fn (OperationDescriptor $d): string => $d->methodName, collectPetstore());

    expect($first)->toBe($second);
});

it('produces a writable variant whose rules() still enforce required non-readOnly fields', function () {
    // The createPet body param is typed PetWritableData (see the writable-variant
    // body test above). This proves that variant is real and that its generated
    // rules() would validate a request: a missing required name fails, the
    // readOnly id is absent. Full HTTP coverage of the writable body is a
    // follow-up (B1); this asserts the generated validation surface directly.
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/petstore.yaml');
    $generator = new ModelGenerator;
    $files = $generator->generate($doc);
    $registry = $generator->registry();

    // (a) The writable variant is genuinely produced and recorded in the registry.
    expect($registry['Pet']['writeClass'])->toBe('PetWritableData')
        ->and($files)->toHaveKey('PetWritableData');

    // (b) Its rules() require the non-readOnly required field and omit readOnly id.
    $writableCode = $files['PetWritableData']->code;
    expect($writableCode)->toContain("'name' => ['required', 'string']")
        ->and($writableCode)->not->toContain("'id' =>");
});

/**
 * Collect the query-parameters fixture WITH the model generator wired in
 * (issue #63), so per-operation query Data classes are emitted. Returns the
 * descriptors plus the generator and collector for warning/file assertions.
 *
 * @return array{0: list<OperationDescriptor>, 1: ModelGenerator, 2: OperationCollector}
 */
function collectQueryParameters(): array
{
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/query-parameters.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);

    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);

    return [$collector->collect($doc), $generator, $collector];
}

it('describes a body-less operation with an injected query Data param (issue #63)', function () {
    [$descriptors] = collectQueryParameters();
    $get = descriptorFor($descriptors, 'get', '/widgets');

    expect($get->queryParam)->toBe(['name' => 'query', 'type' => 'ListWidgetsQueryData', 'injected' => true])
        ->and($get->imports)->toContain('App\\Data\\ListWidgetsQueryData');
});

it('marks the query class non-injected when the operation has a typed body param', function () {
    [$descriptors] = collectQueryParameters();
    $post = descriptorFor($descriptors, 'post', '/widgets');

    // The body would bleed into query validation under container injection, so
    // the class is reachable only via ::fromQuery($request); no import either,
    // since the FQCN appears only as docblock prose.
    expect($post->queryParam)->toBe(['name' => 'query', 'type' => 'CreateWidgetQueryData', 'injected' => false])
        ->and($post->imports)->not->toContain('App\\Data\\CreateWidgetQueryData');
});

it('marks the query class non-injected when the operation falls back to a Request body', function () {
    [$descriptors] = collectQueryParameters();
    $post = descriptorFor($descriptors, 'post', '/untyped');

    expect($post->bodyRequiresRequest)->toBeTrue()
        ->and($post->queryParam)->toBe(['name' => 'query', 'type' => 'UploadBlobQueryData', 'injected' => false]);
});

it('leaves queryParam null for an operation without query parameters', function () {
    [$descriptors] = collectQueryParameters();

    expect(descriptorFor($descriptors, 'get', '/widgets/{widgetId}')->queryParam)->toBeNull();
});

it('leaves queryParam null when every query parameter is un-serializable, with one warning each', function () {
    [$descriptors, $generator] = collectQueryParameters();

    expect(descriptorFor($descriptors, 'get', '/search')->queryParam)->toBeNull();

    $warnings = implode("\n", $generator->warnings());
    expect($warnings)->toContain('query parameter "filter" was skipped: style "deepObject" is not supported yet')
        ->and($warnings)->toContain('query parameter "shape" was skipped: it is an object')
        ->and($warnings)->toContain('query parameter "matrix" was skipped: style "pipeDelimited"')
        ->and($warnings)->toContain('query parameter "csv" was skipped: a non-exploded (explode: false) array')
        ->and($warnings)->toContain('query parameter "payload" was skipped: it declares no schema');
});

it('warns about header and cookie parameters instead of silently dropping them', function () {
    [, , $collector] = collectQueryParameters();

    expect($collector->warnings())->toBe([
        'Operation GET /widgets/{widgetId}: cookie parameter(s) "session" are not generated (cookie parameters are not supported yet).',
        'Operation GET /widgets/{widgetId}: header parameter(s) "X-Trace-Id" are not generated (header parameters are not supported yet).',
        // The octet-stream body on POST /untyped falls back to Request, which
        // also warns since issue #67 (no silent degradation).
        'Operation POST /untyped: the request body declares no application/json schema; no body validation is generated and the controller method falls back to Illuminate\Http\Request.',
    ]);
});

it('merges a PathItem-level query parameter into the generated query class with full rules', function () {
    [, $generator] = collectQueryParameters();
    $files = $generator->queryFiles();

    expect($files)->toHaveKeys(['CreateWidgetQueryData', 'ListWidgetsQueryData', 'UploadBlobQueryData']);

    $code = $files['ListWidgetsQueryData']->code;
    expect($code)->toContain('final class ListWidgetsQueryData extends Data')
        // Required spec param first, then the optionals in spec order
        // (PathItem-level page first, then the operation's own).
        ->and($code)->toContain('public readonly WidgetState $state')
        ->and($code)->toContain('public readonly ?int $page = null')
        ->and($code)->toContain('public readonly ?array $ids = null')
        ->and($code)->toContain('public readonly ?string $q = null')
        // The exact rules pipeline the body classes use: enum membership,
        // numeric bounds, array element rules, string length bounds.
        ->and($code)->toContain("'state' => ['required', Rule::enum(WidgetState::class)]")
        ->and($code)->toContain("'page' => ['sometimes', 'integer', 'min:1']")
        ->and($code)->toContain("'ids' => ['sometimes', 'array']")
        ->and($code)->toContain("'ids.*' => ['integer', 'min:1']")
        ->and($code)->toContain("'q' => ['sometimes', 'string', 'max:64', 'min:2']")
        // Every query class carries the query-only factory.
        ->and($code)->toContain('public static function fromQuery(Request $request): static')
        ->and($code)->toContain('return self::validateAndCreate($request->query->all());');
});

it('maps the boolean true/false literals in fromQuery only when the class has boolean parameters', function () {
    [, $generator] = collectQueryParameters();
    $files = $generator->queryFiles();

    // validateOnly is a boolean: its fromQuery maps the form-style literals to
    // 1/0 before validating (Laravel's boolean rule rejects the literals, and
    // PHP's coercive cast would turn the string "false" into TRUE).
    $boolean = $files['CreateWidgetQueryData']->code;
    expect($boolean)->toContain("foreach (['validateOnly'] as \$name) {")
        ->and($boolean)->toContain("'true' => '1',")
        ->and($boolean)->toContain("'false' => '0',")
        ->and($boolean)->toContain('return self::validateAndCreate($query);');

    // No boolean parameter: the factory stays the simple one-liner.
    $plain = $files['ListWidgetsQueryData']->code;
    expect($plain)->toContain('return self::validateAndCreate($request->query->all());')
        ->and($plain)->not->toContain('foreach');
});

it('suffixes duplicate synthesized method names and pairs each with its own query class', function () {
    $doc = (new SpecParser)->parseFile(__DIR__.'/../../../Fixtures/server/duplicate-method-names.yaml');
    $generator = new ModelGenerator;
    $generator->generate($doc);
    $options = new ServerOptions;
    $descriptors = (new OperationCollector($options, $generator->registry(), null, $generator))->collect($doc);

    // Two operations without operationId collide on the synthesized name: the
    // per-controller allocator suffixes the second method, and the query-class
    // allocator independently suffixes the second class. The pairing must hold:
    // each descriptor references the class generated from ITS parameters.
    $first = descriptorFor($descriptors, 'get', '/pets');
    $second = descriptorFor($descriptors, 'get', '/pets/');

    expect($first->methodName)->toBe('getPets')
        ->and($second->methodName)->toBe('getPets_2')
        ->and($first->queryParam)->toBe(['name' => 'query', 'type' => 'GetPetsQueryData', 'injected' => true])
        ->and($second->queryParam)->toBe(['name' => 'query', 'type' => 'GetPetsQueryData_2', 'injected' => true]);

    // The generated classes carry their own operation's parameter, not the sibling's.
    $files = $generator->queryFiles();
    expect($files['GetPetsQueryData']->code)->toContain("'status' => ['required', 'string']")
        ->and($files['GetPetsQueryData']->code)->not->toContain("'limit'")
        ->and($files['GetPetsQueryData_2']->code)->toContain("'limit' => ['sometimes', 'integer']")
        ->and($files['GetPetsQueryData_2']->code)->not->toContain("'status'");

    // And the emitted controller signatures reference exactly those classes.
    $controller = (new ControllerGenerator($options))->generate($descriptors)['AbstractPetController']->code;
    expect($controller)->toContain('abstract public function getPets(GetPetsQueryData $query): JsonResponse;')
        ->and($controller)->toContain('abstract public function getPets_2(GetPetsQueryData_2 $query): JsonResponse;');
});

it('emits byte-identical query classes across runs (determinism)', function () {
    $codeOf = function (): string {
        [, $generator] = collectQueryParameters();

        return implode("\n", array_map(
            static fn ($file): string => $file->code,
            array_values($generator->queryFiles()),
        ));
    };

    expect($codeOf())->toBe($codeOf());
});
