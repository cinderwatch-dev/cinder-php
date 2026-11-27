<?php

namespace Cinderwatch\Support;

class Redactor
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $sensitiveKeys
     * @return array<string, mixed>
     */
    public static function redact(array $data, array $sensitiveKeys): array
    {
        $normalized = array_map(static fn (string $key): string => strtolower($key), $sensitiveKeys);

        return self::walk($data, $normalized);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $sensitiveKeys
     * @return array<string, mixed>
     */
    private static function walk(array $data, array $sensitiveKeys): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $stringKey = (string) $key;

            if (in_array(strtolower($stringKey), $sensitiveKeys, true)) {
                $result[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $result[$key] = self::walk($value, $sensitiveKeys);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
