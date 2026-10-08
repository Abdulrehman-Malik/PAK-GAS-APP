<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    private bool $started = false;

    public function __construct(private readonly array $config)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('pak_gas_session');
            session_set_cookie_params($this->cookieParams());
            session_start();
        }

        $lastActivity = $_SESSION['_last_activity'] ?? time();
        if ((time() - (int) $lastActivity) > $this->config['session_timeout']) {
            $this->destroy();
            session_name('pak_gas_session');
            session_set_cookie_params($this->cookieParams());
            session_start();
        }

        $_SESSION['_last_activity'] = time();
        $this->started = true;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_last_activity'] = time();
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        $this->started = false;
    }

    private function cookieParams(): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->config['session_secure_cookie'] || $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );
    }
}
