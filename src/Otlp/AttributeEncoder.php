<?php

namespace Cinderwatch\Otlp;

use BackedEnum;
use Stringable;
use UnitEnum;

/**
 * Nulls are dropped because OTLP has no null.
 */
class AttributeEncoder
{
    /**
     * @param  array<array-key, mixed>  $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public function keyValues(array $attributes): array
    {
        $pairs = [];

        foreach ($attributes as $key => $value) {
            $encoded = $this->anyValue($value);

            if ($encoded !== null) {
                $pairs[] = ['key' => (string) $key, 'value' => $encoded];
            }
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function anyValue(mixed $value): ?array
    {
        return match (true) {
            $value === null => null,
            is_string($value) => ['stringValue' => $value],
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => $value],
            is_float($value) => ['doubleValue' => $value],
            is_array($value) && array_is_list($value) => ['arrayValue' => ['values' => $this->listValues($value)]],
            is_array($value) => ['kvlistValue' => ['values' => $this->keyValues($value)]],
            $value instanceof BackedEnum => $this->anyValue($value->value),
            $value instanceof UnitEnum => ['stringValue' => $value->name],
            $value instanceof Stringable => ['stringValue' => (string) $value],
            default => ['stringValue' => get_debug_type($value)],
        };
    }

    /**
     * @param  list<mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function listValues(array $values): array
    {
        $encoded = [];

        foreach ($values as $value) {
            $item = $this->anyValue($value);

            if ($item !== null) {
                $encoded[] = $item;
            }
        }

        return $encoded;
    }
}
