<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    public function query(): array
    {
        return $_GET;
    }

    public function input(): array
    {
        return $_POST;
    }

    public function inputValue(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($uri) || $uri === '') {
            return '/';
        }

        $basePath = parse_url(Config::load(dirname(__DIR__, 2))['app_url'], PHP_URL_PATH);
        if (is_string($basePath) && $basePath !== '/' && str_starts_with($uri, $basePath)) {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }

        return '/' . trim($uri, '/');
    }

    public function file(string $key): ?array
    {
        return isset($_FILES[$key]) && is_array($_FILES[$key]) ? $_FILES[$key] : null;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
