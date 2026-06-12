<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ControllerGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationDescriptor;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;

/**
 * Issue #75: a multipart/form-data object request body synthesizes a
 * per-operation Data class through the same pipeline as the inline JSON
 * bodies of issue #76, with binary file parts typed UploadedFile and given
 * `file` rules (plus `mimetypes:` from `contentMediaType`). Non-binary parts
 * are validated exactly like JSON body fields. JSON wins when an operation
 * declares both media types; non-object multipart keeps the warned Request
 * fallback.
 *
 * @return array{0: list<OperationDescriptor>, 1: ModelGenerator, 2: OperationCollector}
 */
function collectMultipartBody(array $paths, array $schemas = []): array
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => $paths,
    ];
    if ($schemas !== []) {
        $document['components'] = ['schemas' => $schemas];
    }

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry(), null, $generator);
    $descriptors = $collector->collect($spec);

    return [$descriptors, $generator, $collector];
}

function multipartUploadPaths(array $schema, array $extraContent = []): array
{
    return [
        '/pets/upload' => [
            'post' => [
                'tags' => ['Pet'],
                'operationId' => 'uploadPetImage',
                'requestBody' => [
                    'content' => ['multipart/form-data' => ['schema' => $schema]] + $extraContent,
                ],
                'responses' => ['201' => ['description' => 'ok']],
            ],
        ],
    ];
}

it('types a multipart object body as a synthesized per-operation Data param', function () {
    [$descriptors, $generator] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'required' => ['image'],
        'properties' => [
            'image' => ['type' => 'string', 'format' => 'binary'],
            'caption' => ['type' => 'string', 'maxLength' => 80],
        ],
    ]));

    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'UploadPetImageRequestData'])
        ->and($descriptors[0]->bodyRequiresRequest)->toBeFalse()
        ->and($descriptors[0]->imports)->toContain('App\\Data\\Pet\\UploadPetImageRequestData')
        ->and($descriptors[0]->imports)->not->toContain('Illuminate\\Http\\Request');

    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain('Multipart request body of POST /pets/upload.')
        ->and($code)->toContain('use Illuminate\Http\UploadedFile;')
        ->and($code)->toContain('public readonly UploadedFile $image')
        ->and($code)->toContain("'image' => ['required', 'file']")
        // A non-binary part is validated exactly like a JSON body field.
        ->and($code)->toContain('public readonly ?string $caption = null')
        ->and($code)->toContain("'caption' => ['sometimes', 'string', 'max:80']");
});

it('renders the typed multipart param into the abstract controller signature', function () {
    [$descriptors] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'properties' => ['image' => ['type' => 'string', 'format' => 'binary']],
    ]));

    $controllers = (new ControllerGenerator(new ServerOptions))->generate($descriptors);
    $code = $controllers['AbstractPetController']->code;

    expect($code)->toContain('use App\Data\Pet\UploadPetImageRequestData;')
        ->and($code)->toContain('abstract public function store(UploadPetImageRequestData $body): JsonResponse;');
});

it('derives a mimetypes rule from the contentMediaType of a binary part', function () {
    [, $generator] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'properties' => [
            'image' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'image/png'],
            'media' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'image/*'],
        ],
    ]));

    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain("'image' => ['sometimes', 'file', 'mimetypes:image/png']")
        // Laravel's mimetypes rule understands the type/* wildcard form.
        ->and($code)->toContain("'media' => ['sometimes', 'file', 'mimetypes:image/*']");
});

it('drops a malformed contentMediaType instead of embedding it in a rule (untrusted input)', function () {
    [, $generator] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'properties' => [
            'image' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => "image/png',1=>'required"],
        ],
    ]));

    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain("'image' => ['sometimes', 'file']")
        ->and($code)->not->toContain('required');
});

it('types an array of binary items as a list of UploadedFile with per-item file rules', function () {
    [, $generator] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'properties' => [
            'images' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 5,
                'items' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'image/png'],
            ],
        ],
    ]));

    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain('/** @var array<int, UploadedFile> */')
        ->and($code)->toContain('public readonly ?array $images = null')
        ->and($code)->toContain("'images' => ['sometimes', 'array', 'max:5', 'min:1']")
        ->and($code)->toContain("'images.*' => ['file', 'mimetypes:image/png']");
});

it('keeps the plain string typing for a binary string nested below the multipart root', function () {
    [, $generator] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'properties' => [
            'meta' => [
                'type' => 'object',
                'properties' => ['blob' => ['type' => 'string', 'format' => 'binary']],
            ],
        ],
    ]));

    // The nested object is one JSON-serialized part: its binary string is not
    // an upload, so it stays a string in the spawned nested class.
    $files = $generator->bodyFiles();
    expect($files['UploadPetImageRequestMetaData']->code)->toContain('public readonly ?string $blob = null')
        ->and($files['UploadPetImageRequestMetaData']->code)->not->toContain('UploadedFile');
});

it('recognizes a $ref alias to a binary string component as a file part', function () {
    [, $generator] = collectMultipartBody(
        multipartUploadPaths([
            'type' => 'object',
            'properties' => ['image' => ['$ref' => '#/components/schemas/BinaryFile']],
        ]),
        ['BinaryFile' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'image/png']],
    );

    // Without the alias resolution the buildRules() alias path would emit a
    // `string` rule that false-rejects every actual UploadedFile.
    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain('public readonly ?UploadedFile $image = null')
        ->and($code)->toContain("'image' => ['sometimes', 'file', 'mimetypes:image/png']");
});

it('synthesizes a multipart class from a $ref object schema instead of the JSON-semantics component class', function () {
    [$descriptors, $generator] = collectMultipartBody(
        multipartUploadPaths(['$ref' => '#/components/schemas/UploadForm']),
        [
            'UploadForm' => [
                'type' => 'object',
                'required' => ['file'],
                'properties' => [
                    'file' => ['type' => 'string', 'format' => 'binary'],
                    'note' => ['type' => 'string'],
                ],
            ],
        ],
    );

    // The component class types the binary property as string (JSON
    // semantics); the multipart param must NOT reuse it, or the string rule
    // would false-reject uploads. A fresh per-operation class carries the
    // UploadedFile typing instead.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'UploadPetImageRequestData']);

    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain('public readonly UploadedFile $file')
        ->and($code)->toContain("'file' => ['required', 'file']");
});

it('keeps the Request fallback for a multipart $ref that does not resolve to an object component', function () {
    [$descriptors, $generator] = collectMultipartBody(
        multipartUploadPaths(['$ref' => '#/components/schemas/Kind']),
        ['Kind' => ['type' => 'string', 'enum' => ['a', 'b']]],
    );

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($generator->bodyFiles())->toBe([])
        ->and($generator->warnings())->toContain(
            'Operation POST /pets/upload: the multipart/form-data request body $ref "#/components/schemas/Kind" does not resolve to an object component schema; the controller method falls back to Illuminate\Http\Request.',
        );
});

it('keeps the Request fallback for a non-object multipart body, with a warning', function () {
    [$descriptors, $generator] = collectMultipartBody(multipartUploadPaths([
        'type' => 'string',
        'format' => 'binary',
    ]));

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($descriptors[0]->imports)->toContain('Illuminate\\Http\\Request')
        ->and($generator->bodyFiles())->toBe([])
        ->and($generator->warnings())->toContain(
            'Operation POST /pets/upload: the multipart/form-data request body schema was not generated as a typed Data class (it is not an object schema); the controller method falls back to Illuminate\Http\Request.',
        );
});

it('does not fire the no-schema degradation warning for a handled multipart object body', function () {
    [, $generator, $collector] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'properties' => ['image' => ['type' => 'string', 'format' => 'binary']],
    ]));

    $warnings = [...$generator->warnings(), ...$collector->warnings()];
    expect($warnings)->toBe([]);
});

it('prefers the JSON body when an operation declares both application/json and multipart', function () {
    [$descriptors, $generator] = collectMultipartBody(multipartUploadPaths(
        [
            'type' => 'object',
            'properties' => ['image' => ['type' => 'string', 'format' => 'binary']],
        ],
        [
            'application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['url' => ['type' => 'string']],
            ]],
        ],
    ));

    // Documented precedence: the scaffold validates one body shape, and JSON
    // is the established, richer mapping. The synthesized class carries the
    // JSON fields, no UploadedFile typing.
    expect($descriptors[0]->bodyParam)->toBe(['name' => 'body', 'type' => 'UploadPetImageRequestData']);

    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain('Request body of POST /pets/upload.')
        ->and($code)->toContain('public readonly ?string $url = null')
        ->and($code)->not->toContain('UploadedFile');
});

it('emits the write shape for a multipart body with readOnly fields', function () {
    [, $generator] = collectMultipartBody(multipartUploadPaths([
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'image' => ['type' => 'string', 'format' => 'binary'],
        ],
    ]));

    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain('public readonly ?UploadedFile $image = null')
        ->and($code)->not->toContain('$id');
});

it('suffixes a component schema named UploadedFile so the import is never shadowed', function () {
    [, $generator] = collectMultipartBody(
        multipartUploadPaths([
            'type' => 'object',
            'properties' => [
                'image' => ['type' => 'string', 'format' => 'binary'],
                'kind' => ['$ref' => '#/components/schemas/UploadedFile'],
            ],
        ]),
        ['UploadedFile' => ['type' => 'string', 'enum' => ['avatar', 'banner']]],
    );

    // The enum component would otherwise take the bare UploadedFile name (no
    // Data suffix) and collide with the Illuminate import in the body class.
    $code = $generator->bodyFiles()['UploadPetImageRequestData']->code;
    expect($code)->toContain('use Illuminate\Http\UploadedFile;')
        ->and($code)->toContain('public readonly ?UploadedFile $image = null')
        ->and($code)->toContain('public readonly ?UploadedFile_2 $kind = null');
});

it('does not type a multipart body when no model generator is wired in (legacy call sites)', function () {
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Test', 'version' => '1.0.0'],
        'paths' => multipartUploadPaths([
            'type' => 'object',
            'properties' => ['image' => ['type' => 'string', 'format' => 'binary']],
        ]),
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    $generator = new ModelGenerator;
    $generator->generate($spec);
    $collector = new OperationCollector(new ServerOptions, $generator->registry());
    $descriptors = $collector->collect($spec);

    expect($descriptors[0]->bodyParam)->toBeNull()
        ->and($descriptors[0]->bodyRequiresRequest)->toBeTrue()
        ->and($generator->bodyFiles())->toBe([])
        ->and($collector->warnings())->toContain(
            'Operation POST /pets/upload: the request body is multipart/form-data and no model generator is wired in to synthesize a Data class; the controller method falls back to Illuminate\Http\Request.',
        );
});

it('generates byte-identical multipart body classes across two runs (determinism)', function () {
    $run = function (): array {
        [, $generator] = collectMultipartBody(multipartUploadPaths([
            'type' => 'object',
            'required' => ['image'],
            'properties' => [
                'image' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'image/png'],
                'caption' => ['type' => 'string'],
                'gallery' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']],
            ],
        ]));

        return array_map(fn ($file) => $file->code, $generator->bodyFiles());
    };

    expect($run())->toBe($run());
});
