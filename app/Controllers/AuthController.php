<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly Validator $validator,
        private readonly Session $session
    ) {
    }

    public function showLogin(): Response
    {
        if ($this->auth->check()) {
            return Response::redirect($this->auth->mustChangePassword() ? url('/password/change') : url('/'));
        }

        return View::render('auth/login', [
            'pageTitle' => 'Login',
            '_base_path' => base_path(),
        ], null);
    }

    public function login(): Response
    {
        $data = $this->request->input();
        $errors = $this->validator->validate($data, [
            'username' => ['required', 'max:100'],
            'password' => ['required', 'min:6', 'max:255'],
        ]);

        if ($errors !== []) {
            $this->session->flash('errors', $errors);
            $this->session->flash('old', ['username' => (string) ($data['username'] ?? '')]);
            return Response::redirect(url('/login'));
        }

        if (!$this->auth->attempt((string) $data['username'], (string) $data['password'], $this->request->ip())) {
            $this->session->flash('errors', ['auth' => 'Invalid credentials or account temporarily locked.']);
            $this->session->flash('old', ['username' => (string) $data['username']]);
            return Response::redirect(url('/login'));
        }

        return Response::redirect($this->auth->mustChangePassword() ? url('/password/change') : url('/'));
    }

    public function logout(): Response
    {
        $this->auth->logout();
        return Response::redirect(url('/login'));
    }
}
