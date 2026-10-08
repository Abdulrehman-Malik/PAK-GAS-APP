<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];

    public function __construct(
        private readonly Request $request,
        private readonly Auth $auth,
        private readonly Csrf $csrf
    ) {
    }

    public function get(string $path, callable $handler, bool $authRequired = false, ?string $permission = null): void
    {
        $this->add('GET', $path, $handler, $authRequired, $permission);
    }

    public function post(string $path, callable $handler, bool $authRequired = false, ?string $permission = null): void
    {
        $this->add('POST', $path, $handler, $authRequired, $permission);
    }

    public function dispatch(): Response
    {
        $method = $this->request->method();
        $path = $this->request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $matches = [];
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            if ($route['auth_required'] && !$this->auth->check()) {
                return Response::redirect(url('/login'));
            }

            if (
                $route['auth_required']
                && $this->auth->mustChangePassword()
                && $path !== '/password/change'
                && !($method === 'POST' && $path === '/logout')
            ) {
                return Response::redirect(url('/password/change'));
            }

            if ($route['permission'] !== null) {
                $this->auth->require($route['permission']);
            }

            if ($method === 'POST') {
                $this->csrf->verify($this->request);
            }

            return ($route['handler'])(...array_slice($matches, 1));
        }

        return Response::html(View::errorPage('Page not found.', 404), 404);
    }

    private function add(string $method, string $path, callable $handler, bool $authRequired, ?string $permission): void
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $match): string => '([^/]+)',
            $path
        );

        $pattern = is_string($pattern) ? $pattern : $path;
        $pattern = $pattern === '/' ? '' : rtrim($pattern, '/');

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $pattern . '/?$#',
            'handler' => $handler,
            'auth_required' => $authRequired,
            'permission' => $permission,
        ];
    }
}
