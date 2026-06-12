<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Console;

/**
 * Validates operator-supplied options (namespaces, class suffixes/prefixes)
 * before any file is written. These values are interpolated raw into generated
 * `namespace ...;` declarations and class names, so an unchecked value (a stray
 * space, a quote, arbitrary text) produces broken or injected PHP. Validation
 * happens at startup so a misconfiguration fails fast and loudly instead of
 * emitting a corrupt scaffold.
 *
 * @internal
 */
final class OptionValidator
{
    /**
     * A legal PHP namespace: one or more identifier segments joined by
     * backslashes, with an optional leading backslash. Segments allow the same
     * byte range PHP allows for identifiers, including the high-byte range.
     */
    private const NAMESPACE_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    /**
     * A single PHP identifier, used for class-name suffixes and prefixes.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @throws OptionException when the value is not a legal PHP namespace
     */
    public static function namespace(string $option, string $value): string
    {
        if (preg_match(self::NAMESPACE_PATTERN, $value) !== 1) {
            throw new OptionException("Invalid {$option}: '{$value}' is not a legal PHP namespace.");
        }

        return $value;
    }

    /**
     * A class-name suffix or prefix. Empty is allowed only when $allowEmpty.
     *
     * @throws OptionException when the value is not a legal PHP identifier
     */
    public static function identifier(string $option, string $value, bool $allowEmpty = true): string
    {
        if ($value === '') {
            if ($allowEmpty) {
                return $value;
            }

            throw new OptionException("Invalid {$option}: must not be empty.");
        }

        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new OptionException("Invalid {$option}: '{$value}' is not a legal PHP identifier.");
        }

        return $value;
    }
}
