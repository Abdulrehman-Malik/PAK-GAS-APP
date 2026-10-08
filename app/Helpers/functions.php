<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;

function base_path(string $path = ''): string
{
    $root = dirname(__DIR__, 2);
    return $path === '' ? $root : $root . '/' . ltrim($path, '/');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    static $baseUrl = null;

    if ($baseUrl === null) {
        $config = Config::load(base_path());
        $baseUrl = rtrim($config['app_url'], '/');
    }

    return $baseUrl . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('/assets/' . ltrim($path, '/'));
}

function csrf_input(): string
{
    $csrf = $GLOBALS['csrf'] ?? null;
    if (!$csrf instanceof Csrf) {
        return '';
    }

    return '<input type="hidden" name="_csrf" value="' . e($csrf->token()) . '">';
}
