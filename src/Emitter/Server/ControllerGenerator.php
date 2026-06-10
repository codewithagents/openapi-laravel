<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;

/**
 * Emits one abstract controller per tag from the collected operation
 * descriptors. Each operation becomes an abstract method typed against the v1
 * Data classes (typed body or Request in, Data / DataCollection / JsonResponse
 * out). The user extends the abstract with a concrete controller; an
 * unimplemented method is a PHP fatal, so path-level drift is structural.
 *
 * The abstract deliberately does NOT extend Laravel's base Controller: keeping
 * it framework-light means the generated scaffold stays portable and never
 * fights a project's own base class.
 *
 * Output is deterministic: controllers keyed and ordered by abstract class
 * name, methods ordered by path then HTTP method, imports sorted.
 */
final class ControllerGenerator
{
    public function __construct(
        private readonly ServerOptions $options,
    ) {}

    /**
     * @param  list<OperationDescriptor>  $descriptors  already collected once by the caller and shared with RouteGenerator
     * @return array<string, GeneratedFile> abstract class name => file
     */
    public function generate(array $descriptors): array
    {
        /** @var array<string, list<OperationDescriptor>> $byController */
        $byController = [];
        foreach ($descriptors as $descriptor) {
            $byController[$descriptor->abstractClass][] = $descriptor;
        }

        ksort($byController);

        $files = [];
        foreach ($byController as $abstractClass => $operations) {
            $files[$abstractClass] = new GeneratedFile($abstractClass, $this->renderController($abstractClass, $operations));
        }

        return $files;
    }

    /**
     * @param  list<OperationDescriptor>  $operations
     */
    private function renderController(string $abstractClass, array $operations): string
    {
        $imports = [];
        foreach ($operations as $operation) {
            foreach ($operation->imports as $import) {
                $imports[] = $import;
            }
        }
        $imports = array_values(array_unique($imports));
        sort($imports);

        $useBlock = $imports === []
            ? ''
            : implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports))."\n\n";

        $methods = implode("\n\n", array_map(fn (OperationDescriptor $operation): string => $this->renderMethod($operation), $operations));

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->options->controllerNamespace.";\n\n".$useBlock
            .'abstract class '.$abstractClass."\n{\n".$methods."\n}\n";
    }

    private function renderMethod(OperationDescriptor $operation): string
    {
        $doc = ['    /**'];
        $doc[] = '     * '.strtoupper($operation->httpMethod).' '.$this->docblockSafe($operation->path);
        if ($operation->summary !== null) {
            $doc[] = '     *';
            $doc[] = '     * '.$this->docblockSafe($operation->summary);
        }
        if ($operation->returnDoc !== null) {
            $doc[] = '     *';
            $doc[] = '     * @return '.$operation->returnDoc;
        }
        $doc[] = '     */';

        $signature = '    abstract public function '.$operation->methodName.'('
            .implode(', ', $this->parameters($operation)).'): '.$operation->returnType.';';

        return implode("\n", $doc)."\n".$signature;
    }

    /**
     * Neutralize spec-derived free text before it is placed inside a `/** ... *\/`
     * docblock. Two hazards: a literal `*\/` would close the comment early and let
     * the rest of the value inject raw PHP, and newlines or other control
     * characters would let a value forge extra doc lines or break out. We replace
     * every `*\/` with `* /` and collapse all control characters (including
     * newlines and tabs) to a single space. The threat model treats the OpenAPI
     * spec as untrusted input.
     */
    private function docblockSafe(string $value): string
    {
        $value = str_replace('*/', '* /', $value);
        $value = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value);

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function parameters(OperationDescriptor $operation): array
    {
        $params = [];

        if ($operation->bodyParam !== null) {
            $params[] = $operation->bodyParam['type'].' $'.$operation->bodyParam['name'];
        } elseif ($operation->bodyRequiresRequest) {
            $params[] = 'Request $request';
        }

        foreach ($operation->pathParams as $pathParam) {
            $params[] = $pathParam['phpType'].' $'.$pathParam['name'];
        }

        return $params;
    }
}
