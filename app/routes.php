<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\SettingsController;
use App\Core\Validator;
use App\Controllers\PasswordController;
use App\Services\AuditService;
use App\Services\PasswordService;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;

$authController = new AuthController($auth, $request, new Validator(), $session);
$passwordService = new PasswordService($db, new AuditService($db));
$passwordController = new PasswordController($auth, $request, $session, new Validator(), $passwordService);
$homeController = new HomeController($auth);
$settingsController = new SettingsController(
    new SettingsService(new SettingsRepository($db)),
    $auth
);

$router->get('/login', [$authController, 'showLogin']);
$router->post('/login', [$authController, 'login']);
$router->post('/logout', [$authController, 'logout'], true);
$router->get('/password/change', [$passwordController, 'show'], true);
$router->post('/password/change', [$passwordController, 'change'], true);
$router->get('/', [$homeController, 'index'], true, 'dashboard.view');
$router->get('/settings', [$settingsController, 'index'], true, 'settings.view');
