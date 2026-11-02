<?php

declare(strict_types=1);

namespace App;

/**
 * Translations. Flat key catalogs (lang/en.php, lang/fr.php) with `{placeholder}`
 * interpolation. English and French are the launch languages; a test enforces
 * that both files have exactly the same keys.
 */
final class I18n
{
    private const FALLBACK = 'en';

    private static string $locale = self::FALLBACK;

    /** @var array<string, array<string, string>> */
    private static array $catalogs = [];

    /** Resolve the locale for this request and load its catalog. */
    public static function boot(): void
    {
        $available = self::available();

        $candidates = [
            PHP_SAPI === 'cli' ? null : ($_GET['lang'] ?? null),
            PHP_SAPI === 'cli' ? null : ($_COOKIE['pdd_locale'] ?? null),
            PHP_SAPI === 'cli' ? null : ($_SESSION['locale'] ?? null),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $available, true)) {
                self::$locale = $candidate;
                break;
            }
        }

        self::load(self::$locale);
    }

    /** @return list<string> */
    public static function available(): array
    {
        /** @var list<string> $locales */
        $locales = Config::get('app.locales', ['en', 'fr']);
        return $locales;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function setLocale(string $locale): void
    {
        if (!in_array($locale, self::available(), true)) {
            throw new AppError('unsupported_locale', 422, 'That language is not available.');
        }
        self::$locale = $locale;
        $_SESSION['locale'] = $locale;
        self::load($locale);
    }

    /** Language name in its own language, for the switcher. */
    public static function label(string $locale): string
    {
        return $locale === 'fr' ? 'Français' : 'English';
    }

    public static function text(string $locale): string
    {
        return $locale === 'fr' ? 'Français' : 'English';
    }

    /** @param array<string, string|int|float> $vars */
    public static function t(string $key, array $vars = []): string
    {
        $catalog = self::$catalogs[self::$locale] ?? [];
        $template = $catalog[$key] ?? self::$catalogs[self::FALLBACK][$key] ?? $key;

        if ($vars === []) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use ($vars): string {
                $value = $vars[$match[1]] ?? null;
                return $value === null ? $match[0] : (string) $value;
            },
            $template,
        );
    }

    /** Friendly message for an AppError code. */
    public static function error(string $code): string
    {
        $key = 'error.' . $code;
        $catalog = self::$catalogs[self::$locale] ?? [];
        if (isset($catalog[$key])) {
            return self::t($key);
        }
        return self::t('error.internal_error');
    }

    /**
     * Translate for an explicit locale, without changing the request locale.
     * Used for emails, which must go out in the recipient's language.
     *
     * @param array<string, string|int|float> $vars
     */
    public static function forLocale(string $locale, string $key, array $vars = []): string
    {
        if (!in_array($locale, self::available(), true)) {
            $locale = self::FALLBACK;
        }
        self::load($locale);

        $template = self::$catalogs[$locale][$key] ?? self::$catalogs[self::FALLBACK][$key] ?? $key;

        if ($vars === []) {
            return $template;
        }

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use ($vars): string {
                $value = $vars[$match[1]] ?? null;
                return $value === null ? $match[0] : (string) $value;
            },
            $template,
        );
    }

    private static function load(string $locale): void
    {
        if (isset(self::$catalogs[$locale])) {
            return;
        }
        $path = APP_ROOT . '/lang/' . $locale . '.php';
        if (!is_readable($path)) {
            throw new \RuntimeException('Missing language file: ' . $path);
        }
        /** @var array<string, string> $catalog */
        $catalog = require $path;
        self::$catalogs[$locale] = $catalog;
    }
}
