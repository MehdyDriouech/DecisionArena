<?php

declare(strict_types=1);

namespace Domain\CognitiveGovernance;

final class DeterministicHash
{
    /**
     * @param mixed $value
     */
    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    /**
     * @param mixed $value
     */
    public static function canonicalJson(mixed $value): string
    {
        $normalized = self::normalizeValue($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : 'null';
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (self::isList($value)) {
                $out = [];
                foreach ($value as $item) {
                    $out[] = self::normalizeValue($item);
                }

                return $out;
            }

            $out = [];
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                $out[(string)$key] = self::normalizeValue($value[$key]);
            }

            return $out;
        }

        if (is_object($value)) {
            return self::normalizeValue((array)$value);
        }

        if (is_string($value)) {
            return str_replace("\r\n", "\n", $value);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return (string)$value;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}

