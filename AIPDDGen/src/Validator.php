<?php

declare(strict_types=1);

namespace App;

/**
 * Tiny rule-based validator. Rules are strings like "required|email|max:254";
 * failures raise AppError::validation with per-field messages so forms can
 * highlight exactly what needs fixing.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $rules field => "required|email|max:254"
     * @return array<string, mixed> trimmed, normalised values
     */
    public static function validate(array $input, array $rules): array
    {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $input[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            foreach (explode('|', $ruleString) as $rule) {
                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name === 'required') {
                    if ($value === null || $value === '') {
                        $errors[$field] = 'required';
                        continue 2;
                    }
                    continue;
                }

                if ($value === null || $value === '') {
                    continue; // optional field
                }

                $message = self::check($name, $parameter, $value, $field, $input);
                if ($message !== null) {
                    $errors[$field] = $message;
                    continue 2;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = self::normalize($field, $value);
            }
        }

        if ($errors !== []) {
            throw AppError::validation($errors);
        }

        return $validated;
    }

    private static function check(string $name, ?string $parameter, mixed $value, string $field, array $input): ?string
    {
        return match ($name) {
            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) ? null : 'invalid_email',
            'min' => mb_strlen((string) $value) >= (int) $parameter ? null : 'too_short',
            'max' => mb_strlen((string) $value) <= (int) $parameter ? null : 'too_long',
            'in' => in_array((string) $value, explode(',', (string) $parameter), true) ? null : 'invalid_choice',
            'lowercase' => null,
            'confirmed' => (string) ($input[$field . '_confirmation'] ?? '') === (string) $value ? null : 'mismatch',
            'different' => (string) ($input[$parameter ?? ''] ?? '') !== (string) $value ? null : 'must_differ',
            default => null,
        };
    }

    private static function normalize(string $field, mixed $value): mixed
    {
        if ($field === 'email' && is_string($value)) {
            return mb_strtolower($value);
        }
        return $value;
    }
}
