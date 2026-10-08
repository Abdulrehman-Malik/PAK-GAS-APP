<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\SettingsController;
use App\Core\Validator;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;

$authController = new AuthController($auth, $request, new Validator(), $session);
$homeController = new HomeController($auth);
$settingsController = new SettingsController(
    new SettingsService(new SettingsRepository($db)),
    $auth
);

$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->post('/logout', [$authController, 'logout'], true);
$router->get('/', [$homeController, 'index'], true, 'dashboard.view');
$router->get('/settings', [$settingsController, 'index'], true, 'settings.view');
