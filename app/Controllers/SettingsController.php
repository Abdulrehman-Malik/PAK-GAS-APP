<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\View;
use App\Services\SettingsService;

final class SettingsController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Auth $auth
    ) {
    }

    public function index(): Response
    {
        return View::render('settings/index', [
            'pageTitle' => 'Settings',
            'settings' => $this->settings->all(),
            'user' => $this->auth->user(),
            '_base_path' => base_path(),
        ]);
    }
}
