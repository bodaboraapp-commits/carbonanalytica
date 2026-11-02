<?php

declare(strict_types=1);

namespace App;

/**
 * PHP templates with a layout. Escaping is the caller's job for every dynamic
 * value (use the e() helper); templates contain no business logic.
 */
final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): never
    {
        $data['content'] = self::capture($template, $data);

        if ($layout === null) {
            echo $data['content'];
            exit;
        }

        echo self::capture($layout, $data);
        exit;
    }

    /** @param array<string, mixed> $data */
    public static function partial(string $template, array $data = []): void
    {
        echo self::capture($template, $data);
    }

    /** @param array<string, mixed> $data */
    public static function capture(string $template, array $data = []): string
    {
        $path = APP_ROOT . '/templates/' . $template . '.php';
        if (!is_readable($path)) {
            throw new \RuntimeException('Missing template: ' . $path);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $path;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
