<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\InstallerService;

final class InstallerController
{
    public function __construct(
        private readonly InstallerService $installer,
        private readonly Request $request,
        private readonly Session $session,
        private readonly Csrf $csrf
    ) {
    }

    public function handle(): Response
    {
        if ($this->request->method() === 'POST') {
            return $this->install();
        }

        $status = $this->installer->status();

        if ($status['installed'] && $status['pending_migrations'] !== []) {
            try {
                $applied = $this->installer->runPendingMigrations();
                if ($applied !== []) {
                    return Response::redirect(url('/login'));
                }
            } catch (\Throwable $exception) {
                return $this->render($this->installer->status(), $exception->getMessage());
            }
        }

        if ($status['installed'] && $status['pending_migrations'] === []) {
            return Response::redirect(url('/login'));
        }

        return $this->render($status);
    }

    private function install(): Response
    {
        try {
            $this->csrf->verify($this->request);
            $this->installer->install();

            return Response::redirect(url('/login'));
        } catch (\Throwable $exception) {
            return $this->render($this->installer->status(), $exception->getMessage());
        }
    }

    private function render(array $status, ?string $error = null): Response
    {
        return View::render('install/index', [
            'pageTitle' => 'Installation',
            'status' => $status,
            'error' => $error,
            '_base_path' => base_path(),
        ], null);
    }
}
