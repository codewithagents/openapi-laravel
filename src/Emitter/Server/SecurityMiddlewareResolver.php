<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter\Server;

use CodeWithAgents\OpenApiLaravel\Parser\Spec\SecurityRequirementNode;

/**
 * Resolves the spec's security declarations into route middleware through the
 * configured `security.middleware_map` (issue #77). The map is name-based:
 * a scheme name from `components.securitySchemes` maps to one or more Laravel
 * middleware names; scopes are ignored (middleware cannot inspect them).
 *
 * Semantics, in OpenAPI's own terms:
 *
 * - An operation-level `security` array overrides the document-level one
 *   entirely; an explicit `security: []` makes the operation public, so it
 *   gets NO middleware even when global security exists.
 * - Within one requirement object, multiple schemes mean AND: every mapped
 *   scheme contributes its middleware, in spec order, deduplicated.
 * - Multiple requirement objects mean OR, which middleware cannot express.
 *   The FIRST requirement object is enforced and a warning names the ignored
 *   alternatives, once per operation. An empty first requirement (`{}`) means
 *   anonymous access is allowed, so nothing is enforced.
 * - A scheme the spec requires but the map does not name warns once per
 *   scheme: that route stays open, which the operator must learn at
 *   generation time. Mapping a scheme to an empty list is the documented way
 *   to say "handled elsewhere" and silence the warning.
 * - A mapped scheme name absent from `components.securitySchemes` warns once:
 *   it is almost certainly a typo in the config.
 *
 * Warnings are keyed by message (deduplicated) and surface through the
 * operation collector's channel, mirroring the issue #67 degradation warnings.
 *
 * @internal
 */
final class SecurityMiddlewareResolver
{
    /**
     * @var array<string, true>
     */
    private array $warnings = [];

    /**
     * @param  array<string, list<string>>  $middlewareMap  scheme name => middleware names (security.middleware_map)
     * @param  list<SecurityRequirementNode>  $globalSecurity  the document-level `security` array; empty means none
     */
    public function __construct(
        private readonly array $middlewareMap,
        private readonly array $globalSecurity,
    ) {}

    /**
     * Warn for every mapped scheme name the spec does not declare in
     * `components.securitySchemes`: the mapping can never match anything, so
     * it is almost certainly a typo on the config side.
     *
     * @param  list<string>  $declaredSchemes
     */
    public function warnUndeclaredMappings(array $declaredSchemes): void
    {
        foreach (array_keys($this->middlewareMap) as $scheme) {
            if (! in_array($scheme, $declaredSchemes, true)) {
                $this->warnings[sprintf(
                    'security.middleware_map maps scheme "%s", but the spec declares no security scheme with that name in components.securitySchemes; check the map for a typo.',
                    $scheme,
                )] = true;
            }
        }
    }

    /**
     * The middleware names the generated route for one operation must carry,
     * in deterministic order (spec order of the enforced requirement object,
     * deduplicated).
     *
     * @param  string  $label  "GET /pets", for warning messages
     * @param  list<SecurityRequirementNode>|null  $operationSecurity  the operation's own `security`; null means "not declared, inherit global", [] means "explicitly public"
     * @return list<string>
     */
    public function middlewareFor(string $label, ?array $operationSecurity): array
    {
        // Operation-level security overrides global entirely; an explicit
        // empty array is the spec's way of opting an operation out, so it
        // must NOT fall through to the global requirements.
        $requirements = $operationSecurity ?? $this->globalSecurity;

        if ($requirements === []) {
            return [];
        }

        // The typed SecurityRequirementNode carries the dynamic scheme-name
        // map as a plain array (issue #104), so the names are a direct read;
        // the old cebe path needed a getSerializableData() escape here.
        $alternatives = [];
        foreach ($requirements as $requirement) {
            $alternatives[] = $requirement->schemeNames();
        }

        if (count($alternatives) > 1) {
            $this->warnAlternatives($label, $alternatives);
        }

        $middleware = [];
        foreach ($alternatives[0] as $scheme) {
            if (! array_key_exists($scheme, $this->middlewareMap)) {
                $this->warnings[sprintf(
                    'Security scheme "%s" is required by the spec but has no entry in security.middleware_map; the generated routes requiring it carry no auth middleware. Map it to one or more middleware names, or to an empty list to acknowledge it is handled elsewhere.',
                    $scheme,
                )] = true;

                continue;
            }

            foreach ($this->middlewareMap[$scheme] as $name) {
                if (! in_array($name, $middleware, true)) {
                    $middleware[] = $name;
                }
            }
        }

        return $middleware;
    }

    /**
     * Non-fatal diagnostics, keyed by message for deduplication, in the same
     * shape the operation collector keeps its own warnings.
     *
     * @return array<string, true>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * One warning per operation whose security declares OR alternatives:
     * middleware is a flat AND list, so only the first requirement object is
     * enforced and every ignored alternative is named.
     *
     * @param  list<list<string>>  $alternatives
     */
    private function warnAlternatives(string $label, array $alternatives): void
    {
        $ignored = array_map(
            fn (array $schemes): string => $this->describeAlternative($schemes),
            array_slice($alternatives, 1),
        );

        $this->warnings[sprintf(
            'Operation %s: security declares %d alternative requirements (OR), which middleware cannot express; only the first alternative %s is enforced, ignored: %s.',
            $label,
            count($alternatives),
            $this->describeAlternative($alternatives[0]),
            implode(', ', $ignored),
        )] = true;
    }

    /**
     * Render one requirement object for a warning message: "(bearerAuth)" for
     * a single scheme, "(a + b)" for an AND pair, "(anonymous access)" for the
     * empty requirement the spec uses to allow unauthenticated calls.
     *
     * @param  list<string>  $schemes
     */
    private function describeAlternative(array $schemes): string
    {
        return $schemes === [] ? '(anonymous access)' : '('.implode(' + ', $schemes).')';
    }
}
