<?php

declare(strict_types=1);

use App\I18n;

return [
    'english and french catalogs have identical keys' => static function (): void {
        $en = require APP_ROOT . '/lang/en.php';
        $fr = require APP_ROOT . '/lang/fr.php';

        $missingInFrench = array_diff(array_keys($en), array_keys($fr));
        $missingInEnglish = array_diff(array_keys($fr), array_keys($en));

        assert_same([], array_values($missingInFrench), 'keys missing from French: ' . implode(', ', $missingInFrench));
        assert_same([], array_values($missingInEnglish), 'keys missing from English: ' . implode(', ', $missingInEnglish));
    },

    'every translated value is a non-empty string' => static function (): void {
        foreach (['en', 'fr'] as $locale) {
            $catalog = require APP_ROOT . '/lang/' . $locale . '.php';
            foreach ($catalog as $key => $value) {
                assert_true(is_string($value), "{$locale}:{$key} must be a string");
                assert_true($value !== '', "{$locale}:{$key} must not be empty");
            }
        }
    },

    'interpolates placeholders' => static function (): void {
        $english = I18n::forLocale('en', 'invite.title', ['team' => 'Kisumu']);
        $french = I18n::forLocale('fr', 'invite.title', ['team' => 'Kisumu']);
        assert_contains('Kisumu', $english);
        assert_contains('Kisumu', $french);
        assert_true($english !== $french, 'French translation must differ from English');
    },

    'falls back to english for unknown keys' => static function (): void {
        $value = I18n::forLocale('fr', 'this.key.does.not.exist');
        assert_same('this.key.does.not.exist', $value);
    },

    'maps unknown error codes to a generic message' => static function (): void {
        assert_same(I18n::t('error.internal_error'), I18n::error('totally_unknown_code'));
    },
];
