<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Small method + path router. Supports {param} placeholders, e.g. /api/users/{id}.
 * Handlers are callables or [ControllerClass::class, 'method'] pairs and receive
 * the Request. Optional middleware (callables taking the Request) run first and
 * stop the request by throwing HttpException.
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable|array, middleware: list<callable>}>> */
    private array $routes = [];

    /** @var list<callable> middleware run for every matched route, before route middleware */
    private array $global = [];

    public function use(callable $middleware): void
    {
        $this->global[] = $middleware;
    }

    /** @param list<callable> $middleware */
    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param list<callable> $middleware */
    public function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[self::normalise($path)][strtoupper($method)] = ['handler' => $handler, 'middleware' => $middleware];
    }

    public function dispatch(string $method, string $uri, string $basePath = ''): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = self::normalise($path);
        $request = new Request(strtoupper($method), $path);

        try {
            foreach ($this->routes as $pattern => $routes) {
                $params = self::match($pattern, $path);

                if ($params === null) {
                    continue;
                }

                if (!isset($routes[$request->method])) {
                    header('Allow: ' . implode(', ', array_keys($routes)));
                    throw new HttpException(405, 'method_not_allowed', 'Method not allowed.');
                }

                $route = $routes[$request->method];
                $request->params = $params;

                foreach ([...$this->global, ...$route['middleware']] as $middleware) {
                    $middleware($request);
                }

                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $handler = [new $class(), $action];
                }

                $handler($request);
                return;
            }

            throw HttpException::notFound('Endpoint not found.');
        } catch (HttpException $e) {
            foreach ($e->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            JsonResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->details);
        }
    }

    /** @return array<string, string>|null */
    private static function match(string $pattern, string $path): ?array
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    private static function normalise(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
