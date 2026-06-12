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
final class EnumEmitter
{
    public function __construct(
        private readonly GenerationState $state,
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
     * @param  list<string|int>  $values
     */
    private function enumBacking(array $values): string
    {
        foreach ($values as $value) {
            if (! is_int($value) && ! (is_string($value) && $value !== '' && strspn($value, '0123456789') === strlen($value))) {
                return 'string';
            }
        }

        return 'int';
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
