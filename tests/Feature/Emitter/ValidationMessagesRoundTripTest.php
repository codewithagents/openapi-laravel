<?php

declare(strict_types=1);

use App\MsgData\CustomerData;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GeneratorOptions;
use CodeWithAgents\OpenApiLaravel\Emitter\ModelGenerator;
use Illuminate\Validation\ValidationException;

/**
 * Behavioral gate for the validation extension trait (issue #83): the
 * user-owned trait named by output.validation_trait must actually customize
 * validation messages and attribute names through the REAL Laravel Validator,
 * with laravel-data v4's method_exists() discovery of static messages() /
 * attributes() (the documented working-with-the-validator hooks). And because
 * generated Data classes are overwritten on every regenerate, the test proves
 * regeneration cannot clobber the customization: the trait file is never part
 * of the generated file set, a regenerate is byte-identical, and the custom
 * message still surfaces after the generated files are rewritten on disk.
 */
function msgRoundTripSpec(): OpenApi
{
    $document = [
        'openapi' => '3.0.3',
        'info' => ['title' => 'Messages', 'version' => '1.0.0'],
        'paths' => new stdClass,
        'components' => ['schemas' => [
            'Customer' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'minLength' => 5],
                ],
            ],
        ]],
    ];

    $spec = Reader::readFromJson((string) json_encode($document), OpenApi::class);
    expect($spec)->toBeInstanceOf(OpenApi::class);

    return $spec;
}

/**
 * @return array<string, GeneratedFile>
 */
function generateMsgClasses(): array
{
    $generator = new ModelGenerator(new GeneratorOptions(
        namespace: 'App\\MsgData',
        validationTrait: 'App\\MsgSupport\\CustomApiMessages',
    ));

    return $generator->generate(msgRoundTripSpec());
}

function msgRoundTripDir(): string
{
    return sys_get_temp_dir().'/oal_messages_'.getmypid();
}

function bootMsgClasses(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $dir = msgRoundTripDir();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // The user-owned trait. It is written ONCE here and never appears in any
    // generated file set: regeneration cannot touch it. laravel-data resolves
    // both hooks via app()->call([$class, 'messages'|'attributes']), so plain
    // static methods on the trait are picked up through the final Data class.
    $trait = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace App\MsgSupport;

    trait CustomApiMessages
    {
        /**
         * @return array<string, string>
         */
        public static function messages(): array
        {
            return ['name.required' => 'Custom: every customer needs a name.'];
        }

        /**
         * @return array<string, string>
         */
        public static function attributes(): array
        {
            return ['email' => 'e-mail address'];
        }
    }
    PHP;

    file_put_contents($dir.'/CustomApiMessages.php', $trait);
    require_once $dir.'/CustomApiMessages.php';

    foreach (generateMsgClasses() as $file) {
        $path = $dir.'/'.$file->filename();
        file_put_contents($path, $file->code);
        require_once $path;
    }

    $booted = true;
}

beforeEach(fn () => bootMsgClasses());

it('surfaces the trait-supplied custom message through the real Laravel Validator', function () {
    try {
        CustomerData::validate(['email' => 'long-enough']);
        $this->fail('expected a ValidationException for the missing name');
    } catch (ValidationException $e) {
        expect($e->errors()['name'])->toContain('Custom: every customer needs a name.');
    }
});

it('surfaces the trait-supplied attribute name in other rule messages', function () {
    try {
        CustomerData::validate(['name' => 'Ada', 'email' => 'ab']);
        $this->fail('expected a ValidationException for the short email');
    } catch (ValidationException $e) {
        // The min:5 message is Laravel's own, but it must name the field with
        // the attributes() label, proving attributes() reached the validator.
        expect(implode(' ', $e->errors()['email']))->toContain('e-mail address');
    }
});

it('still validates and hydrates a spec-valid payload with the trait in place', function () {
    $data = CustomerData::validateAndCreate(['name' => 'Ada', 'email' => 'ada@example.com']);

    expect($data->name)->toBe('Ada')
        ->and($data->email)->toBe('ada@example.com');
});

it('survives regeneration: the trait is never generated, regen is byte-identical, and the custom message persists', function () {
    $dir = msgRoundTripDir();
    $traitBefore = file_get_contents($dir.'/CustomApiMessages.php');

    // Regenerate from the same spec, exactly what `openapi:generate` would do
    // on a refresh, and overwrite the generated files on disk.
    $regenerated = generateMsgClasses();

    // The user-owned trait file is not part of the generated file set, so a
    // regenerate (even with --prune, which deletes only *.php in the OUTPUT
    // directory before rewriting the planned files) cannot emit over it.
    expect(array_keys($regenerated))->toBe(['CustomerData'])
        ->and($regenerated['CustomerData']->filename())->toBe('CustomerData.php');

    foreach ($regenerated as $file) {
        // Byte-identical regeneration: overwriting changes nothing on disk.
        expect($file->code)->toBe((string) file_get_contents($dir.'/'.$file->filename()));
        file_put_contents($dir.'/'.$file->filename(), $file->code);
    }

    expect(file_get_contents($dir.'/CustomApiMessages.php'))->toBe($traitBefore);

    // And the customization still surfaces after the rewrite.
    try {
        CustomerData::validate(['email' => 'long-enough']);
        $this->fail('expected a ValidationException for the missing name');
    } catch (ValidationException $e) {
        expect($e->errors()['name'])->toContain('Custom: every customer needs a name.');
    }
});
