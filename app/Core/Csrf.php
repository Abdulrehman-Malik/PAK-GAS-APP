<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) < 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function verify(Request $request): void
    {
        $submitted = $request->header('X-CSRF-TOKEN') ?? $request->inputValue('_csrf');
        $expected = $this->session->get(self::SESSION_KEY);

        if (
            !is_string($submitted)
            || !is_string($expected)
            || $submitted === ''
            || !hash_equals($expected, $submitted)
        ) {
            throw new \RuntimeException('Invalid CSRF token.');
        }
    }
}
