<?php

declare(strict_types=1);

use App\Password;

return [
    'hashes and verifies a password' => static function (): void {
        $hash = Password::hash('correct horse battery staple');
        assert_true($hash !== 'correct horse battery staple');
        assert_true(Password::verify('correct horse battery staple', $hash));
        assert_false(Password::verify('wrong password', $hash));
    },

    'uses a unique salt per password' => static function (): void {
        $first = Password::hash('same password');
        $second = Password::hash('same password');
        assert_true($first !== $second, 'identical passwords must not produce identical hashes');
        assert_true(Password::verify('same password', $first));
        assert_true(Password::verify('same password', $second));
    },

    'rejects empty or malformed hashes' => static function (): void {
        assert_false(Password::verify('anything', null));
        assert_false(Password::verify('anything', ''));
        assert_false(Password::verify('anything', 'not-a-hash'));
    },

    'hashes unicode passwords' => static function (): void {
        $password = 'Mot de passe très sûr ✅';
        $hash = Password::hash($password);
        assert_true(Password::verify($password, $hash));
    },

    'does not need a rehash for a fresh hash' => static function (): void {
        assert_false(Password::needsRehash(Password::hash('fresh password')));
    },
];
