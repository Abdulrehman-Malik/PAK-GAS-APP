<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Services\PasswordService;

final class PasswordController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly Session $session,
        private readonly Validator $validator,
        private readonly PasswordService $passwordService
    ) {
    }

    public function show(): Response
    {
        return View::render('auth/change-password', [
            'pageTitle' => 'Change Password',
            'user' => $this->auth->user(),
            '_base_path' => base_path(),
        ], null);
    }

    public function change(): Response
    {
        $data = $this->request->input();
        $errors = $this->validator->validate($data, [
            'current_password' => ['required', 'max:255'],
            'new_password' => ['required', 'min:8', 'max:255'],
            'password_confirmation' => ['required', 'min:8', 'max:255'],
        ]);

        if ($errors !== []) {
            $this->session->flash('errors', $errors);
            return Response::redirect(url('/password/change'));
        }

        try {
            $user = $this->auth->user();
            if ($user === null) {
                return Response::redirect(url('/login'));
            }

            $this->passwordService->change(
                (int) $user['id'],
                (string) $data['current_password'],
                (string) $data['new_password'],
                (string) $data['password_confirmation'],
                $this->request->ip()
            );
        } catch (\InvalidArgumentException $exception) {
            $this->session->flash('errors', ['auth' => $exception->getMessage()]);
            return Response::redirect(url('/password/change'));
        }

        $this->session->flash('success', 'Password changed successfully.');
        return Response::redirect(url('/'));
    }
}
