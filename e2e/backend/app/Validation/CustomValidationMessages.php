<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Consumer-owned validation-message trait for the output.validation_trait
 * config proof (issue #83).
 *
 * config/openapi-laravel.php sets output.validation_trait to this FQCN, so the
 * generator weaves a `use CustomValidationMessages;` body line into EVERY
 * generated Data class. laravel-data discovers the static messages() method via
 * method_exists() and feeds it to the underlying Laravel validator, so a failing
 * rule on a keyed field returns this custom message instead of Laravel's
 * default.
 *
 * It lives in App\Validation (NOT app/Data, which is entirely generated and
 * gitignored): this trait is hand-written, versioned business logic, the
 * regeneration-safe home laravel-data documents for static messages() /
 * attributes() hooks. The generated Data class is overwritten on every run, the
 * trait is not.
 *
 * The keys target the /lab/trait-check `code` field (a required,
 * pattern-constrained string -> required + regex rules). They are deliberately
 * scoped to that one field so enabling the trait globally does not change the
 * message text any other e2e test asserts on; every other Data class carries the
 * trait too but has no matching key, so its messages stay at Laravel's default.
 */
trait CustomValidationMessages
{
    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'code.required' => 'CUSTOM: code is required',
            'code.regex' => 'CUSTOM: code is malformed',
        ];
    }
}
