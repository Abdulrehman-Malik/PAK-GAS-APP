<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private function __construct(
        private readonly string $body,
        private readonly int $status,
        private readonly string $contentType
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/html; charset=UTF-8');
    }

    public static function json(array $payload, int $status = 200): self
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return new self($body, $status, 'application/json; charset=UTF-8');
    }

    public static function binary(string $body, string $contentType, ?string $downloadName = null): self
    {
        if ($downloadName !== null) {
            header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
            header('X-Content-Type-Options: nosniff');
        }
        return new self($body, 200, $contentType);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        header('Location: ' . $url, true, $status);
        return new self('', $status, 'text/html; charset=UTF-8');
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: ' . $this->contentType);
        echo $this->body;
    }
}
