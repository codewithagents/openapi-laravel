<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Inlines the runtime support classes into the consumer's own namespace (issue
 * #40). Today the generated Data classes import their rules and the map
 * transformer from the generator package's own `CodeWithAgents\OpenApiLaravel\
 * Support\...` namespace, which makes the generator a permanent RUNTIME
 * dependency of every consuming app and lets a `composer update` silently change
 * validation/serialization under already-generated code.
 *
 * This emitter fixes that: it copies each needed support class verbatim from its
 * canonical `src/Support/X.php` template, rewriting only the `namespace` line to
 * the consumer's Support namespace (the Data namespace plus a `\Support`
 * suffix). The result is a self-contained, owned, drift-checked file the
 * consumer commits like every other generated class, so generated output has
 * zero runtime coupling to the generator.
 *
 * The canonical `src/Support/*` files stay the single source of truth: the
 * emitter reads them off disk via the well-known class-file path so the inlined
 * copy can never drift from the template the generator package itself ships.
 *
 * Only the support classes a given spec actually references are emitted (the
 * generator records each one as it wires the import), so a spec with no
 * `hostname` format never carries a HostnameRule, and `NoUnknownPropertiesRule`
 * appears only under `--enforce-closed-objects`. Same spec in -> byte-identical
 * support set out.
 */
final readonly class SupportClassEmitter
{
    /**
     * The canonical namespace every `src/Support/*` template declares. Rewritten
     * to the consumer's Support namespace on inline.
     */
    private const CANONICAL_NAMESPACE = 'CodeWithAgents\\OpenApiLaravel\\Support';

    public function __construct(
        private string $supportNamespace,
    ) {}

    /**
     * Render one support class into the consumer's Support namespace. $shortName
     * is the class short name (e.g. `Rfc3339DateTimeRule`); the template is read
     * from the package's canonical `src/Support/<shortName>.php`.
     */
    public function emit(string $shortName): GeneratedFile
    {
        $template = $this->templatePath($shortName);

        $source = @file_get_contents($template);
        if ($source === false) {
            throw new GenerationException("Missing canonical support template for {$shortName} at {$template}.");
        }

        $rewritten = str_replace(
            'namespace '.self::CANONICAL_NAMESPACE.';',
            'namespace '.$this->supportNamespace.';',
            $source,
        );

        return new GeneratedFile($shortName, $rewritten);
    }

    /**
     * The absolute path of a canonical support template. The `src/Support`
     * directory sits two levels up from this emitter (`src/Emitter`), so the
     * lookup is location-independent and needs no autoloader.
     */
    private function templatePath(string $shortName): string
    {
        return dirname(__DIR__).'/Support/'.$shortName.'.php';
    }
}
