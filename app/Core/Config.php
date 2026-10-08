<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    public static function load(string $basePath): array
    {
        Env::load($basePath);

        return [
            'base_path' => $basePath,
            'app_env' => self::env('APP_ENV', 'production'),
            'app_url' => rtrim(self::env('APP_URL', 'http://localhost/pak-gas-app/public'), '/'),
            'app_name' => self::env('APP_NAME', 'Pak Gas POS'),
            'timezone' => self::env('TIMEZONE', 'Asia/Karachi'),
            'db' => [
                'host' => self::env('DB_HOST', '127.0.0.1'),
                'port' => (int) self::env('DB_PORT', '3306'),
                'name' => self::env('DB_NAME', 'pak_gas'),
                'user' => self::env('DB_USER', 'root'),
                'pass' => self::env('DB_PASS', ''),
            ],
            'session_timeout' => max(300, (int) self::env('SESSION_TIMEOUT', '1800')),
            'session_secure_cookie' => filter_var(self::env('SESSION_SECURE_COOKIE', 'false'), FILTER_VALIDATE_BOOL),
        ];
    }

    private static function env(string $key, string $default): string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}
