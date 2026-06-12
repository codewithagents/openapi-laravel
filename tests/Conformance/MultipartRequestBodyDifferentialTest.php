<?php

declare(strict_types=1);

use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\OperationCollector;
use CodeWithAgents\OpenApiLaravel\Emitter\Server\ServerOptions;
use CodeWithAgents\OpenApiLaravel\Parser\OpenApiReader;
use CodeWithAgents\OpenApiLaravel\Parser\SchemaNormalizer;
use CodeWithAgents\OpenApiLaravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/*
 * Differential validation oracle for multipart/form-data request bodies
 * (issue #75, extending the inline-body oracle of issue #76).
 *
 * The synthesized `<Operation>RequestData` class types binary parts as
 * UploadedFile with `file` / `mimetypes:` rules, so this oracle proves with
 * REAL fake uploads (Illuminate\Http\Testing\File through UploadedFile::fake())
 * and the real Laravel validator that a spec-valid upload is accepted, a
 * missing or non-file value for a file part is rejected, a wrong MIME type is
 * rejected, and the non-binary parts keep their full JSON-grade rules. A
 * hydration test boots an actual multipart Request through the generated
 * class and asserts the property holds the UploadedFile instance.
 *
 * The validator needs a booted Laravel container, so this test opts into the
 * Testbench TestCase explicitly (the Pest config only binds it under
 * tests/Feature).
 */
uses(TestCase::class);

/**
 * Generate the multipart oracle's classes once and load them into the running
 * process, through the same collector wiring the planner uses.
 *
 * @return class-string
 */
function multipartOracleClass(): string
{
    static $class = null;
    if ($class !== null) {
        return $class;
    }

    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'MultipartOracle', 'version' => '1.0.0'],
        'paths' => [
            '/uploads' => [
                'post' => [
                    'operationId' => 'createUpload',
                    'requestBody' => [
                        'content' => ['multipart/form-data' => ['schema' => [
                            'type' => 'object',
                            'required' => ['document', 'title'],
                            'properties' => [
                                'document' => ['type' => 'string', 'format' => 'binary', 'contentMediaType' => 'text/plain'],
                                'attachments' => [
                                    'type' => 'array',
                                    'maxItems' => 2,
                                    'items' => ['type' => 'string', 'format' => 'binary'],
                                ],
                                'title' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 20],
                                'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                            ],
                        ]]],
                    ],
                    'responses' => ['201' => ['description' => 'created']],
                ],
            ],
        ],
    ];

    $decoded = json_decode((string) json_encode($document), true);
    $normalized = SchemaNormalizer::normalize($decoded);
    $spec = (new OpenApiReader)->read($normalized);
    $specCebe = Reader::readFromJson((string) json_encode($normalized), OpenApi::class);

    $namespace = 'MultipartOracle\\Models';
    $generator = new ModelGenerator(new GeneratorOptions($namespace));
    $files = $generator->generate($spec);
    (new OperationCollector(new ServerOptions(dataNamespace: $namespace), $generator->registry(), null, $generator))->collect($specCebe);

    $dir = sys_get_temp_dir().'/oal_multipart_oracle_'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    loadGeneratedFiles($dir, [
        ...array_values($generator->supportFiles()),
        ...array_values($files),
        ...array_values($generator->bodyFiles()),
    ]);

    // The oracle operation carries no tag, so its body class lands in the
    // Untagged group of the tag-grouped layout (issue #93, the only layout).
    /** @var class-string $class */
    $class = $namespace.'\\Untagged\\CreateUploadRequestData';
    expect(class_exists($class))->toBeTrue('the multipart oracle class was not generated');

    return $class;
}

/**
 * A fake text upload whose CONTENT is real text, so finfo (which Laravel's
 * mimetypes rule consults via getMimeType()) detects text/plain.
 */
function fakeTextUpload(string $name = 'notes.txt'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, 'plain text content for the multipart oracle');
}

/**
 * A fake upload finfo detects as application/x-empty / inode-x-empty, never
 * text/plain, so it violates the mimetypes constraint while still being a
 * perfectly valid FILE.
 */
function fakeBinaryUpload(string $name = 'blob.bin'): UploadedFile
{
    return UploadedFile::fake()->create($name, 4);
}

/**
 * Run one payload through the synthesized class's validate(). Returns 'accept'
 * when validation passes and 'reject' when it throws ValidationException; any
 * other throwable surfaces as 'error:<message>'.
 *
 * @param  array<string, mixed>  $payload
 */
function multipartOracleOutcome(array $payload): string
{
    try {
        /** @var callable $validator */
        $validator = [multipartOracleClass(), 'validate'];
        $validator($payload);

        return 'accept';
    } catch (ValidationException) {
        return 'reject';
    } catch (Throwable $e) {
        return 'error:'.$e->getMessage();
    }
}

dataset('spec_valid_multipart_bodies', [
    'file and required text part' => [fn (): array => ['document' => fakeTextUpload(), 'title' => 'Quarterly notes']],
    'all parts present and valid' => [fn (): array => [
        'document' => fakeTextUpload(),
        'attachments' => [fakeBinaryUpload('a.bin'), fakeBinaryUpload('b.bin')],
        'title' => 'ab',
        'priority' => 5,
    ]],
    'single attachment in the list' => [fn (): array => [
        'document' => fakeTextUpload(),
        'attachments' => [fakeBinaryUpload()],
        'title' => 'Notes',
    ]],
]);

dataset('spec_invalid_multipart_bodies', [
    'missing required file part' => [fn (): array => ['title' => 'Notes']],
    'plain string where a file is required' => [fn (): array => ['document' => 'not-a-file', 'title' => 'Notes']],
    'wrong MIME type for the pinned part' => [fn (): array => ['document' => fakeBinaryUpload(), 'title' => 'Notes']],
    'non-file element in the file list' => [fn (): array => ['document' => fakeTextUpload(), 'attachments' => ['nope'], 'title' => 'Notes']],
    'too many attachments' => [fn (): array => [
        'document' => fakeTextUpload(),
        'attachments' => [fakeBinaryUpload('a.bin'), fakeBinaryUpload('b.bin'), fakeBinaryUpload('c.bin')],
        'title' => 'Notes',
    ]],
    'missing required text part' => [fn (): array => ['document' => fakeTextUpload()]],
    'text part above maxLength' => [fn (): array => ['document' => fakeTextUpload(), 'title' => str_repeat('x', 21)]],
    'integer part above maximum' => [fn (): array => ['document' => fakeTextUpload(), 'title' => 'Notes', 'priority' => 6]],
]);

it('accepts every spec-valid multipart payload through the real validator', function (Closure $payload) {
    expect(multipartOracleOutcome($payload()))->toBe('accept');
})->with('spec_valid_multipart_bodies');

it('rejects every spec-invalid multipart payload through the real validator', function (Closure $payload) {
    expect(multipartOracleOutcome($payload()))->toBe('reject');
})->with('spec_invalid_multipart_bodies');

it('hydrates the UploadedFile from a real multipart request through the generated class', function () {
    $class = multipartOracleClass();

    $document = fakeTextUpload('report.txt');
    $attachment = fakeBinaryUpload('extra.bin');
    $request = Request::create(
        '/uploads',
        'POST',
        ['title' => 'Quarterly notes', 'priority' => 3],
        [],
        ['document' => $document, 'attachments' => [$attachment]],
    );

    $data = $class::from($request);

    expect($data->document)->toBeInstanceOf(UploadedFile::class)
        ->and($data->document->getClientOriginalName())->toBe('report.txt')
        ->and($data->attachments)->toHaveCount(1)
        ->and($data->attachments[0])->toBeInstanceOf(UploadedFile::class)
        ->and($data->title)->toBe('Quarterly notes')
        ->and($data->priority)->toBe(3);
});
