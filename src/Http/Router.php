<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Intentionally small: a path-pattern -> callable table per HTTP method.
 * Patterns use {name} for a single path segment, e.g. "/tokens/{id}".
 * Good enough for this API's size; swap for a real routing library if
 * the route table grows much beyond this.
 */
final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = $this->normalize((string) parse_url($uri, PHP_URL_PATH));

        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $params = $this->match($pattern, $path);
            if ($params !== null) {
                $handler($params);
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }

    private function normalize(string $path): string
    {
        // the front controller lives at public/api/index.php, so incoming
        // request paths arrive as "/api/..." - strip that prefix here once
        // rather than repeating "/api" in every route pattern.
        $path = preg_replace('#^/api#', '', $path) ?? $path;
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        // route patterns are hand-written literals (see routes.php), so no
        // need to escape regex metacharacters beyond the {param} substitution
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);

        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
