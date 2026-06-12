<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

use CodeWithAgents\OpenApiLaravel\Emitter\GeneratedFile;
use CodeWithAgents\OpenApiLaravel\Emitter\GenerationException;

/**
 * Emits one abstract controller per tag from the collected operation
 * descriptors. Each operation becomes an abstract method typed against the v1
 * Data classes (typed body or Request in, Data / DataCollection / JsonResponse
 * out). The user extends the abstract with a concrete controller; an
 * unimplemented method is a PHP fatal, so path-level drift is structural.
 *
 * The abstract deliberately does NOT extend Laravel's base Controller by
 * default: keeping it framework-light means the generated scaffold stays
 * portable and never fights a project's own base class. A project that wants
 * its controllers rooted in a base class (Laravel's
 * App\Http\Controllers\Controller, or its own) sets controllers.base_class
 * (issue #83) and every generated abstract extends it.
 *
 * Output is deterministic: controllers keyed and ordered by abstract class
 * name, methods ordered by path then HTTP method, imports sorted.
 *
 * @internal
 */
final readonly class ControllerGenerator
{
    public function __construct(
        private ServerOptions $options,
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

        $extends = $this->extendsClause($abstractClass, $imports);

        sort($imports);

        $useBlock = $imports === []
            ? ''
            : implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports))."\n\n";

        $methods = implode("\n\n", array_map(fn (OperationDescriptor $operation): string => $this->renderMethod($operation), $operations));

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->options->controllerNamespace.";\n\n".$useBlock
            .'abstract class '.$abstractClass.$extends."\n{\n".$methods."\n}\n";
    }

    /**
     * The ` extends Base` clause for the configured controllers.base_class
     * (issue #83), or '' when none is configured (the default, byte-identical
     * to the historical base-class-free output).
     *
     * The base is referenced by its short name with a `use` import added when
     * it lives outside the controller namespace, the form Laravel Pint's
     * `fully_qualified_strict_types` fixer produces, so a consumer's own Pint
     * run never rewrites the file and trips the drift gate. A short name that
     * collides with the abstract class itself or with a differently-rooted
     * existing import would emit a PHP fatal, so it fails loudly here instead
     * of writing a broken file.
     *
     * @param  list<string>  $imports  the controller's imports; the base FQCN is appended when needed
     */
    private function extendsClause(string $abstractClass, array &$imports): string
    {
        $base = $this->options->controllerBaseClass;
        if ($base === null) {
            return '';
        }

        $fqcn = ltrim($base, '\\');
        $separator = strrpos($fqcn, '\\');
        $shortName = $separator === false ? $fqcn : substr($fqcn, $separator + 1);
        $baseNamespace = $separator === false ? '' : substr($fqcn, 0, $separator);

        if ($shortName === $abstractClass) {
            throw new GenerationException(
                "controllers.base_class '{$base}' has the same short name as the generated abstract controller {$abstractClass}; rename the base class or the colliding tag."
            );
        }

        foreach ($imports as $import) {
            $importShort = ($pos = strrpos($import, '\\')) === false ? $import : substr($import, $pos + 1);
            if ($importShort === $shortName && $import !== $fqcn) {
                throw new GenerationException(
                    "controllers.base_class '{$base}' short name collides with the import {$import} in {$abstractClass}; use a base class whose short name does not clash."
                );
            }
        }

        if ($baseNamespace !== ltrim($this->options->controllerNamespace, '\\') && ! in_array($fqcn, $imports, true)) {
            $imports[] = $fqcn;
        }

        return ' extends '.$shortName;
    }

    private function renderMethod(OperationDescriptor $operation): string
    {
        $doc = ['    /**'];
        $doc[] = '     * '.strtoupper($operation->httpMethod).' '.$this->docblockSafe($operation->path);
        if ($operation->summary !== null) {
            $doc[] = '     *';
            $doc[] = '     * '.$this->docblockSafe($operation->summary);
        }
        if ($operation->queryParam !== null && ! $operation->queryParam['injected']) {
            // The query class is NOT injected here: the request body occupies
            // the payload, and container resolution would validate the query
            // class against the merged body + query input. Point the
            // implementer at the explicit query-only factory instead. The FQCN
            // is spelled out as prose, not imported, so no `use` goes unused.
            // The collector resolved it, because under the grouped data layout
            // (issue #93) the class may live in a tag subnamespace.
            $doc[] = '     *';
            $doc[] = '     * Query parameters: validate and hydrate them with';
            $doc[] = '     * \\'.$operation->queryParam['fqcn'].'::fromQuery($request).';
        }
        if ($operation->needsStatusMiddleware()) {
            // The spec declares a non-200 success status; the generated route
            // enforces it via the RespondsWithStatus middleware (issue #64),
            // so the implementer keeps returning the plain value. For a 204
            // the method returns void: a 204 carries no body, and the
            // middleware guarantees the empty response.
            $doc[] = '     *';
            $doc[] = $operation->successStatus === 204
                ? '     * Responds with HTTP 204: return nothing, the generated route sets the status.'
                : '     * Responds with HTTP '.$operation->successStatus.' (set by the generated route).';
        }
        if ($operation->returnDoc !== null) {
            $doc[] = '     *';
            $doc[] = '     * @return '.$operation->returnDoc;
        }
        $doc[] = '     */';

        $signature = '    abstract public function '.$operation->methodName.'('
            .implode(', ', $operation->parameterDeclarations()).'): '.$operation->returnType.';';

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
}
