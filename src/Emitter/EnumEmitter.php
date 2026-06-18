<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Emitter;

use CodeWithAgents\OpenApiLaravel\Naming\PhpIdentifier;
use CodeWithAgents\OpenApiLaravel\Naming\UniqueNames;
use CodeWithAgents\OpenApiLaravel\Parser\Spec\SchemaNode;

/**
 * Emits a component schema as a native PHP backed enum (issue #109, extracted
 * from ModelGenerator): backing-type inference (int when every value is an
 * integer-shaped string or int, string otherwise), collision-safe case names,
 * and the rendered enum file, written into the run's file bucket via the
 * shared {@see GenerationState}.
 *
 * @internal
 */
final readonly class EnumEmitter
{
    public function __construct(
        private GenerationState $state,
    ) {}

    public function emitEnum(string $className, SchemaNode $schema): void
    {
        // emitEnum only runs for a backed-enum component (isEnum), whose values
        // are all int or string; filtering floats here also narrows the type for
        // the backing/case helpers, which a native PHP enum cannot back on a float.
        $values = [];
        foreach (SchemaFacts::enumValues($schema) as $value) {
            if (is_int($value) || is_string($value)) {
                $values[] = $value;
            }
        }

        $backing = $this->enumBacking($values);
        $cases = new UniqueNames;
        $lines = [];

        foreach ($values as $value) {
            $caseName = $this->enumCaseName($value, $backing);
            $caseName = $cases->reserve($caseName);
            $literal = $backing === 'int' ? (string) (int) $value : "'".PhpLiteral::escapeSingleQuoted((string) $value)."'";
            $lines[] = '    case '.$caseName.' = '.$literal.';';
        }

        $body = implode("\n", $lines);

        // A deprecated enum component carries a class-level `@deprecated` so the
        // generated enum gets the same IDE/PHPStan deprecation signal as a Data
        // class.
        $deprecationTag = SchemaFacts::deprecationTag($schema);
        $docBlock = $deprecationTag !== null ? '/**'."\n".' * '.$deprecationTag."\n".' */'."\n" : '';

        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$this->state->namespaceFor($className).";\n\n".$docBlock.'enum '.$className.': '.$backing."\n{\n".$body."\n}\n";

        $this->state->files[$className] = new GeneratedFile($className, $code, $this->state->fileGroups[$className] ?? null);
    }

    /**
     * The enum is int-backed only when EVERY value can round-trip as an int
     * without losing information; otherwise it is string-backed.
     *
     * @param  list<string|int>  $values
     */
    private function enumBacking(array $values): string
    {
        foreach ($values as $value) {
            if (! $this->isIntBackable($value)) {
                return 'string';
            }
        }

        return 'int';
    }

    /**
     * Whether a value can back a native PHP int enum without corruption. A
     * native int always can. A string can ONLY when it is an unsigned-digit
     * string that ALSO round-trips through int unchanged (issue #145).
     *
     * The old check accepted any unsigned-digit string, which silently
     * corrupted a leading-zero wire value: `"01"` was emitted as
     * `case Value1 = 1`, so the spec value `"01"` no longer round-tripped (a
     * consumer sending `"01"` never matched), and a sibling `"1"` collapsed to
     * the SAME `1`, producing a fatal "Duplicate value in enum" PHP error. The
     * added `(string) (int) $value === $value` round-trip test rejects every
     * non-canonical decimal form (`"01"`, `"040000"`, `"00"`) so such an enum
     * falls back to a faithful string backing instead. The unsigned-digit gate
     * is kept so a signed string like `"-1"` stays string-backed exactly as
     * before, leaving the backing decision for existing specs unchanged.
     */
    private function isIntBackable(string|int $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return $value !== ''
            && strspn($value, '0123456789') === strlen($value)
            && (string) (int) $value === $value;
    }

    private function enumCaseName(string|int $value, string $backing): string
    {
        if ($backing === 'int') {
            $int = (int) $value;

            return $int < 0 ? 'ValueMinus'.abs($int) : 'Value'.$int;
        }

        $name = PhpIdentifier::toClassName((string) $value);

        return $name === '_' ? 'Value' : $name;
    }
}
