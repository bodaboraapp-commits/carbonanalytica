<?php

declare(strict_types=1);

use App\Tokens;

return [
    'generates unique URL-safe tokens' => static function (): void {
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $token = Tokens::generate();
            assert_true(preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1, 'token must be URL-safe');
            assert_true(strlen($token) >= 40, 'token must be long enough');
            $tokens[] = $token;
        }
        assert_same(50, count(array_unique($tokens)), 'tokens must not repeat');
    },

    'hashes tokens deterministically as sha256' => static function (): void {
        $token = Tokens::generate();
        $hash = Tokens::hash($token);
        assert_same(64, strlen($hash));
        assert_same($hash, Tokens::hash($token));
        assert_true($hash !== $token, 'stored value must not be the token itself');
    },

    'compares secrets in constant time' => static function (): void {
        assert_true(Tokens::equals('same-secret', 'same-secret'));
        assert_false(Tokens::equals('same-secret', 'other-secret'));
    },
];
