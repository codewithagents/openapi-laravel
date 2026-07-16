<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

/**
 * Synthesizes the per-operation `<Operation>Errors` throwable-factory class:
 * one static method per spec-declared error response whose JSON schema
 * resolves to a NAMED-COMPONENT object (v1 scope). Each method is named by its
 * HTTP status (`badRequest`/`notFound`/... via the derived 4xx+5xx table),
 * flattens the target error Data class's constructor into named parameters
 * (mirroring that constructor exactly, nested/array fields keep their sub-DTO
 * array typing), and forwards into `new ApiError(new <Schema>Data(...), <status>)`.
 * A concrete controller then answers a spec error with one throw:
 *
 *     throw GetPetByIdErrors::notFound(message: 'No such pet.');
 *
 * Deliberately lightweight: it needs ONLY {@see GenerationState}, because the
 * flattened parameter model for every already-emitted Data class was captured
 * ONCE during ModelGenerator::emitData()'s original pass (see
 * {@see GenerationState::$constructorParams}). This class never calls the type
 * resolver or the emission pipeline again, so it cannot double-emit an inline
 * nested class the way a naive re-derivation would.
 *
 * @internal
 */
final class ErrorFactorySynthesizer
{
    /**
     * HTTP status -> factory method name, mechanically camelCased from
     * Symfony's HttpFoundation `Response::HTTP_*` constants, with the same
     * deliberate deviations the shipped `Support\ApiError` carrier's own named
     * factories use so a status covered by both layers reads identically
     * (422 -> `unprocessable`, not `unprocessableEntity`; 500 -> `serverError`,
     * not `internalServerError`). Injective by construction (Symfony's own
     * UPPER_SNAKE suffixes are distinct), so two different statuses in one
     * operation can never derive the same method name. A concrete 4xx/5xx code
     * absent from this table falls back to `status<code>` (e.g. `status480`).
     *
     * @var array<int, string>
     */
    private const STATUS_NAMES = [
        400 => 'badRequest', 401 => 'unauthorized', 402 => 'paymentRequired',
        403 => 'forbidden', 404 => 'notFound', 405 => 'methodNotAllowed',
        406 => 'notAcceptable', 407 => 'proxyAuthenticationRequired',
        408 => 'requestTimeout', 409 => 'conflict', 410 => 'gone',
        411 => 'lengthRequired', 412 => 'preconditionFailed',
        413 => 'payloadTooLarge', 414 => 'uriTooLong',
        415 => 'unsupportedMediaType', 416 => 'rangeNotSatisfiable',
        417 => 'expectationFailed', 418 => 'imATeapot',
        421 => 'misdirectedRequest', 422 => 'unprocessable', 423 => 'locked',
        424 => 'failedDependency', 425 => 'tooEarly', 426 => 'upgradeRequired',
        428 => 'preconditionRequired', 429 => 'tooManyRequests',
        431 => 'requestHeaderFieldsTooLarge',
        451 => 'unavailableForLegalReasons', 500 => 'serverError',
        501 => 'notImplemented', 502 => 'badGateway',
        503 => 'serviceUnavailable', 504 => 'gatewayTimeout',
        505 => 'httpVersionNotSupported', 506 => 'variantAlsoNegotiates',
        507 => 'insufficientStorage', 508 => 'loopDetected',
        510 => 'notExtended', 511 => 'networkAuthenticationRequired',
    ];

    public function __construct(
        private readonly GenerationState $state,
    ) {}

    /**
     * Emit the `<Operation>Errors` factory class for one operation's qualifying
     * error slots (already classified by the collector, sorted by status). The
     * target Data class of every slot is guaranteed to carry a captured
     * constructor model, so this method only READS the stored model and never
     * re-runs emission. Returns the reserved class name, or null when there are
     * no slots (defensive: the collector never calls with an empty list).
     *
     * @param  string  $baseName  StudlyCaps operation context (the same operationId-or-fallback the body/response classes use)
     * @param  string  $operationLabel  "GET /pets/{petId}", for the class docblock
     * @param  ?string  $tag  the operation's first tag (or the 'Untagged' fallback), so the grouped layout (issue #93) places the factory in its operation's tag group; ignored in the flat layout
     * @param  list<array{status: int, dataClass: string}>  $slots  qualifying error slots in ascending status order
     * @return string|null the reserved factory class name, or null when there are no slots
     */
    public function generate(string $baseName, string $operationLabel, ?string $tag, array $slots): ?string
    {
        if ($slots === []) {
            return null;
        }

        $className = $this->state->names->reserve($baseName.'Errors');
        $this->state->fileGroups[$className] = $tag !== null ? TagGroups::forTag($tag) : null;

        // ApiError is the carrier every method forwards into; importing it here
        // marks it used, so it is inlined into the consumer's Support namespace
        // exactly when a factory class is emitted (the unified trigger).
        $imports = [$this->state->supportImport('ApiError')];
        $refs = [];
        $methods = [];

        foreach ($slots as $slot) {
            $dataClass = $slot['dataClass'];
            $params = $this->state->constructorParams[$dataClass] ?? [];

            $refs[] = $dataClass;
            foreach ($params as $param) {
                foreach ($param['type']->classRefs as $ref) {
                    $refs[] = $ref;
                }
            }

            $methods[] = $this->renderMethod($this->methodNameFor($slot['status']), $dataClass, $slot['status'], $params);
        }

        // Same-group references stay short-name-only; a cross-group Data class
        // (or an enum/Data class named in a parameter's docblock type) is
        // imported from its real namespace, exactly like every other emitter.
        $imports = $this->state->withCrossGroupImports($className, $imports, array_values(array_unique($refs)));

        $this->state->errorFactoryFiles[$className] = new GeneratedFile(
            $className,
            $this->renderClass($className, $operationLabel, $imports, $methods),
            $this->state->fileGroups[$className] ?? null,
        );

        return $className;
    }

    private function methodNameFor(int $status): string
    {
        return self::STATUS_NAMES[$status] ?? 'status'.$status;
    }

    /**
     * Render one static factory method: the flattened signature, an optional
     * PHPDoc line for any parameter carrying a richer generic (an array of a
     * sub-DTO, an object union), and the single forwarding return.
     *
     * @param  list<array{wireName: string, phpName: string, type: ResolvedType, required: bool, default: ?string}>  $params
     */
    private function renderMethod(string $methodName, string $dataClass, int $status, array $params): string
    {
        $signatureParts = [];
        $argParts = [];
        $docLines = [];

        foreach ($params as $param) {
            $signatureParts[] = $this->paramSignature($param);
            $argParts[] = $param['phpName'].': $'.$param['phpName'];

            if ($param['type']->docType !== null) {
                $docLines[] = '@param  '.$param['type']->docType.'  $'.$param['phpName'];
            }
        }

        $doc = '';
        if ($docLines !== []) {
            $doc = "    /**\n".implode("\n", array_map(static fn (string $line): string => '     * '.$line, $docLines))."\n     */\n";
        }

        return $doc
            .'    public static function '.$methodName.'('.implode(', ', $signatureParts).'): ApiError'."\n"
            ."    {\n"
            .'        return new ApiError(new '.$dataClass.'('.implode(', ', $argParts).'), '.$status.');'."\n"
            .'    }';
    }

    /**
     * One flattened parameter declaration, mirroring the target Data class's
     * own constructor parameter exactly (required -> the type's nullable-aware
     * declaration, a scalar default -> the seeded literal, otherwise the
     * optional `?T = null` form) so a caller passes the same values the Data
     * class would accept.
     *
     * @param  array{wireName: string, phpName: string, type: ResolvedType, required: bool, default: ?string}  $param
     */
    private function paramSignature(array $param): string
    {
        $type = $param['type'];

        if ($param['required']) {
            return $type->declaration().' $'.$param['phpName'];
        }

        if ($param['default'] !== null) {
            $declaration = $type->nullable ? $this->optionalDeclaration($type) : $type->declaration;

            return $declaration.' $'.$param['phpName'].' = '.$param['default'];
        }

        return $this->optionalDeclaration($type).' $'.$param['phpName'].' = null';
    }

    /**
     * The optional (defaulting-to-null) declaration of a type, matching
     * {@see ClassRenderer}'s own rule: `mixed` already includes null, a genuine
     * multi-member union spells null as a trailing `|null` member (PHP forbids
     * `?A|B`), and every single type uses the `?T` shorthand.
     */
    private function optionalDeclaration(ResolvedType $type): string
    {
        if ($type->declaration === 'mixed') {
            return 'mixed';
        }

        if ($type->isMultiMemberUnion()) {
            return str_ends_with($type->declaration, '|null') ? $type->declaration : $type->declaration.'|null';
        }

        return '?'.$type->declaration;
    }

    /**
     * Assemble the final class source: the header, the ApiError (and any
     * cross-group) imports, an explanatory docblock naming the operation, and
     * the static factory methods.
     *
     * @param  list<string>  $imports
     * @param  list<string>  $methods  already-rendered method bodies
     */
    private function renderClass(string $className, string $operationLabel, array $imports, array $methods): string
    {
        $useBlock = implode("\n", array_map(static fn (string $fqcn): string => 'use '.$fqcn.';', $imports));

        $docLines = [
            'Throwable factories for the spec-declared error responses of '.PhpLiteral::docblockSafe($operationLabel).'.',
            '',
            'Each method builds the operation\'s declared error Data class and wraps it in',
            'an ApiError at the response\'s HTTP status, so a concrete controller answers a',
            'spec error with one throw and never breaks the success return type.',
        ];
        $docBlock = "/**\n".implode("\n", array_map(static fn (string $line): string => $line === '' ? ' *' : ' * '.$line, $docLines))."\n */\n";

        $header = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->state->namespaceFor($className).";\n\n".$useBlock."\n\n".$docBlock.'final class '.$className;

        return $header."\n{\n".implode("\n\n", $methods)."\n}\n";
    }
}
