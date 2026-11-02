<?php

declare(strict_types=1);

/**
 * Application configuration accessor.
 */
namespace App;

final class Config
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /** @param array<string, mixed> $config */
    public static function load(array $config): void
    {
        self::$config = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
