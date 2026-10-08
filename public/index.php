<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

try {
    $config = Config::load(dirname(__DIR__));
    date_default_timezone_set($config['timezone']);

    $session = new Session($config);
    $session->start();

    $db = DB::fromConfig($config);
    $csrf = new Csrf($session);
    $auth = new Auth($db, $session, $config);
    $request = new Request();

    $GLOBALS['session'] = $session;
    $GLOBALS['csrf'] = $csrf;
    $GLOBALS['auth'] = $auth;
    $GLOBALS['db'] = $db;

    $router = new Router($request, $auth, $csrf);
    require dirname(__DIR__) . '/app/routes.php';

    $response = $router->dispatch();
    $response->send();
} catch (Throwable $exception) {
    $logger = new Logger(dirname(__DIR__) . '/storage/logs');
    $logger->exception($exception);

    $status = $exception->getCode() === 403 ? 403 : 500;
    $message = $status === 403
        ? 'Access denied.'
        : (isset($config) && $config['app_env'] === 'development'
            ? 'Application error: ' . $exception->getMessage()
            : 'Something went wrong. Please try again or contact the administrator.');

    Response::html(View::errorPage($message, $status), $status)->send();
}
