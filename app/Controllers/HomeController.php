<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\View;

final class HomeController
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function index(): Response
    {
        return View::render('home/index', [
            'pageTitle' => 'Dashboard',
            'user' => $this->auth->user(),
            '_base_path' => base_path(),
        ]);
    }
}
