<?php

declare(strict_types=1);

namespace CodeWithAgents\OpenApiLaravel\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Asserts a value is an RFC1123 hostname (the OpenAPI `format: hostname`).
 *
 * A hostname is one or more dot-separated labels. Each label is 1 to 63
 * characters of ASCII letters, digits, or hyphens, and may not start or end with
 * a hyphen. The whole hostname is capped at 253 characters, the classic DNS
 * limit. A bare single label like `localhost` is accepted, since a hostname need
 * not be fully qualified. So `example.com`, `api.service.io`, and `localhost`
 * pass, while `not a hostname` (spaces), `bad_host!` (underscore, bang), and a
 * leading-hyphen label like `-bad.com` are rejected.
 *
 * A trailing dot (the fully qualified `example.com.` form) is tolerated by
 * trimming a single trailing dot before the per-label check.
 *
 * A non-string value passes here and is left to the accompanying `string` rule.
 *
 * Attached by the generator to string properties whose schema declares
 * `format: hostname` via a `new HostnameRule` rule expression. The
 * internationalized `idn-hostname` format is intentionally NOT routed here: a
 * strict ASCII label regex would wrongly reject valid unicode labels, so the
 * generator keeps `idn-hostname` on a softer check rather than over-engineering
 * unicode/punycode validation.
 */
final class HostnameRule implements ValidationRule
{
    /**
     * One RFC1123 label: a letter or digit, optionally followed by up to 62
     * letters, digits, or hyphens, ending in a letter or digit. This forbids a
     * leading or trailing hyphen and caps the label at 63 characters.
     */
    private const LABEL = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            // Not a string: the accompanying string rule owns this case.
            return;
        }

        $candidate = $value;

        // Tolerate a single trailing dot (the fully qualified form).
        if (str_ends_with($candidate, '.') && $candidate !== '.') {
            $candidate = substr($candidate, 0, -1);
        }

        if ($candidate === '' || strlen($candidate) > 253) {
            $fail('The :attribute must be a valid hostname.');

            return;
        }

        if (preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*$/', $candidate) !== 1) {
            $fail('The :attribute must be a valid hostname.');
        }
    }
}
