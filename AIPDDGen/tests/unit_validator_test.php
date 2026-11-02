<?php

declare(strict_types=1);

use App\AppError;

use App\Validator;

return [
    'accepts valid input and normalises the email' => static function (): void {
        $validated = Validator::validate(
            ['email' => '  Ada@Example.COM ', 'password' => 'correct horse battery staple'],
            ['email' => 'required|email|max:254', 'password' => 'required|min:12|max:200'],
        );

        assert_same('ada@example.com', $validated['email']);
        assert_same('correct horse battery staple', $validated['password']);
    },

    'rejects a missing required field' => static function (): void {
        assert_app_error('validation_error', static function (): void {
            Validator::validate(['email' => ''], ['email' => 'required|email']);
        });
    },

    'rejects an invalid email address' => static function (): void {
        try {
            Validator::validate(['email' => 'not-an-email'], ['email' => 'required|email']);
            throw new RuntimeException('Expected a validation error');
        } catch (AppError $error) {
            assert_same('invalid_email', $error->fields['email'] ?? null);
        }
    },

    'enforces minimum and maximum lengths' => static function (): void {
        try {
            Validator::validate(['password' => 'short'], ['password' => 'required|min:12']);
            throw new RuntimeException('Expected a validation error');
        } catch (AppError $error) {
            assert_same('too_short', $error->fields['password'] ?? null);
        }

        try {
            Validator::validate(['name' => str_repeat('a', 121)], ['name' => 'required|max:120']);
            throw new RuntimeException('Expected a validation error');
        } catch (AppError $error) {
            assert_same('too_long', $error->fields['name'] ?? null);
        }
    },

    'enforces a choice list' => static function (): void {
        try {
            Validator::validate(['role' => 'owner'], ['role' => 'required|in:admin,editor,viewer']);
            throw new RuntimeException('Expected a validation error');
        } catch (AppError $error) {
            assert_same('invalid_choice', $error->fields['role'] ?? null);
        }
    },

    'skips rules for optional empty fields' => static function (): void {
        $validated = Validator::validate(['description' => ''], ['description' => 'max:2000']);
        assert_same('', $validated['description']);
    },
];
