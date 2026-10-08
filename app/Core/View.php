<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'main'): Response
    {
        $basePath = $data['_base_path'] ?? dirname(__DIR__, 2);
        $viewPath = $basePath . '/app/Views/' . $view . '.php';

        if (!is_file($viewPath)) {
            throw new \RuntimeException('View not found: ' . $view);
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        $content = (string) ob_get_clean();

        if ($layout === null) {
            return Response::html($content);
        }

        $layoutPath = $basePath . '/app/Views/layouts/' . $layout . '.php';
        if (!is_file($layoutPath)) {
            throw new \RuntimeException('Layout not found: ' . $layout);
        }

        ob_start();
        require $layoutPath;
        return Response::html((string) ob_get_clean());
    }

    public static function errorPage(string $message, int $status): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><main style="max-width:720px;margin:4rem auto;padding:2rem;font-family:system-ui"><h1>Application error</h1><p>' . e($message) . '</p></main></body></html>';
    }
}
