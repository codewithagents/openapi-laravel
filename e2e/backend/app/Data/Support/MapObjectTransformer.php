<?php

declare(strict_types=1);

namespace App\Data\Support;

use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/**
 * Serializes an `additionalProperties` map (`array<string, X>`) as a JSON object,
 * even when it is empty.
 *
 * PHP cannot tell an empty associative array apart from an empty list, so
 * `json_encode([])` emits a JSON array `[]`. Strict clients that expect a JSON
 * object reject that. Casting the value to an object forces `json_encode` to emit
 * `{}` for an empty map while preserving the exact keys and values of a non-empty
 * one. `null` is left untouched so a nullable map still serializes as `null`.
 *
 * Attached by the generator to every map-typed property via
 * `#[WithTransformer(MapObjectTransformer::class)]`.
 */
final class MapObjectTransformer implements Transformer
{
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed
    {
        if (! is_array($value)) {
            // Leave null (and anything not array-shaped) exactly as-is.
            return $value;
        }

        // Casting an array to object yields a stdClass whose properties are the
        // array entries. An empty array becomes an empty stdClass, so json_encode
        // emits `{}` instead of `[]`; a non-empty array keeps its keys and values.
        return (object) $value;
    }
}
