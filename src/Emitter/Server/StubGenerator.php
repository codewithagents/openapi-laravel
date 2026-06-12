<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;

/**
 * Emits one CONCRETE controller stub per generated abstract controller
 * (issue #78): `final class PetController extends AbstractPetController` with
 * every abstract method implemented as a `throw new LogicException(...)`
 * placeholder. The stubs are the exact classes the generated routes file
 * references, so a fresh project boots immediately after generate + scaffold
 * instead of fataling on the first request.
 *
 * Stubs are one-time output the user owns: the scaffold command skips a stub
 * whose file already exists, regeneration never overwrites one, and the drift
 * gate (`openapi:check`) never inspects them.
 *
 * Signatures are built from the same OperationDescriptor fields the abstract
 * controller emitter uses (parameterDeclarations(), returnType, returnDoc),
 * so a stub can never disagree with the abstract method it overrides.
 *
 * Output is deterministic: stubs keyed and ordered by concrete class name,
 * methods in descriptor order (path then HTTP method), imports sorted.
 *
 * @internal
 */
final readonly class StubGenerator
{
    public function __construct(
        private ServerOptions $options,
    ) {}

    /**
     * @param  list<OperationDescriptor>  $descriptors  already collected once by the caller, the same list the abstract controllers and routes were generated from
     * @return array<string, GeneratedFile> concrete class name => file
     */
    public function generate(array $descriptors): array
    {
        /** @var array<string, list<OperationDescriptor>> $byController */
        $byController = [];
        foreach ($descriptors as $descriptor) {
            $byController[$descriptor->controllerClass][] = $descriptor;
        }

        ksort($byController);

        $files = [];
        foreach ($byController as $controllerClass => $operations) {
            $files[$controllerClass] = new GeneratedFile($controllerClass, $this->renderStub($controllerClass, $operations));
        }

        return $files;
    }

    /**
     * @param  list<OperationDescriptor>  $operations
     */
    private function renderStub(string $controllerClass, array $operations): string
    {
        // Every stub method throws, so LogicException is always imported. The
        // rest are the same imports the abstract controller carries: the stub
        // reproduces every signature, so it references the same types.
        $imports = ['LogicException'];
        foreach ($operations as $operation) {
            foreach ($operation->imports as $import) {
                $imports[] = $import;
            }
        }
        $imports = array_values(array_unique($imports));
        sort($imports);

        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports))."\n\n";

        $abstractClass = $operations[0]->abstractClass;

        $classDoc = "/**\n"
            ." * Scaffolded once by openapi-laravel (issue #78). This file is yours: it is\n"
            ." * never overwritten, never regenerated, and never drift-checked. Replace\n"
            ." * each placeholder body with your implementation.\n"
            ." */\n";

        $methods = implode("\n\n", array_map(fn (OperationDescriptor $operation): string => $this->renderMethod($operation), $operations));

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->options->controllerNamespace.";\n\n".$useBlock
            .$classDoc
            .'final class '.$controllerClass.' extends '.$abstractClass."\n{\n".$methods."\n}\n";
    }

    private function renderMethod(OperationDescriptor $operation): string
    {
        // Only the @return docblock is repeated here: a DataCollection return
        // needs its generics to stay PHPStan-clean, and the IDE-visible
        // documentation (verb, path, summary, query hints) lives on the
        // abstract method directly above this override.
        $doc = '';
        if ($operation->returnDoc !== null) {
            $doc = "    /**\n     * @return ".$operation->returnDoc."\n     */\n";
        }

        $signature = '    public function '.$operation->methodName.'('
            .implode(', ', $operation->parameterDeclarations()).'): '.$operation->returnType;

        // The method name is a whitelisted PHP identifier (never raw spec
        // text), so it is safe inside the single-quoted message.
        $body = "    {\n        throw new LogicException('Not implemented: ".$operation->methodName.".');\n    }";

        return $doc.$signature."\n".$body;
    }
}
