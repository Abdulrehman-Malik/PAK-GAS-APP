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

function money(string|int $value): string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
        return '0.00';
    }
    $negative = str_starts_with($value, '-');
    $value = ltrim($value, '+-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
    $result = ltrim($whole, '0');
    $result = $result === '' ? '0' : $result;
    return ($negative && $result !== '0' ? '-' : '') . $result . '.' . $fraction;
}

function decimal3(string|int $value): string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
        return '0.000';
    }
    $negative = str_starts_with($value, '-');
    $value = ltrim($value, '+-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
    $result = ltrim($whole, '0');
    $result = $result === '' ? '0' : $result;
    return ($negative && $result !== '0' ? '-' : '') . $result . '.' . $fraction;
}
