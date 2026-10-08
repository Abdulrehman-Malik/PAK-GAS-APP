<?php

declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public function __construct(private readonly string $directory)
    {
    }

    public function exception(\Throwable $exception): void
    {
        $this->write(
            'ERROR',
            $exception->getMessage() . PHP_EOL . $exception->getTraceAsString()
        );
    }

    public function write(string $level, string $message): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            PHP_EOL
        );

        file_put_contents(
            $this->directory . '/app-' . date('Y-m-d') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }
}
