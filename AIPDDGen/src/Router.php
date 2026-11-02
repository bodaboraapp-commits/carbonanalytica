<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal router: method + "/path/{param}" patterns mapped to controllers.
 * No framework, no regex config files.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): never
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = $this->match($route['pattern'], $path);
            if ($params === null) {
                continue;
            }
            if ($route['method'] !== strtoupper($method)) {
                $allowed[] = $route['method'];
                continue;
            }
            ($route['handler'])($params);
            exit;
        }

        if ($allowed !== []) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            throw new AppError('method_not_allowed', 405, 'That request method is not allowed here.');
        }

        throw AppError::notFound('That page');
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $pattern,
        );
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode($value);
            }
        }
        return $params;
    }
}
