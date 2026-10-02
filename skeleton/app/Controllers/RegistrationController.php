<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Support\Auth;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Validation\Validator;
use Phpvin\View\ViewFactory;

final class RegistrationController
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly Validator $validator,
        private readonly Auth $auth,
    ) {}

    public function create(): Response
    {
        return $this->views->response('auth/register');
    }

    public function store(Request $request): Response
    {
        $data = $this->validator->validate($request->all(), [
            'name' => 'required|max:100',
            'email' => 'required|email|max:255',
            'password' => 'required|min:12|confirmed',
        ]);

        if (User::emailIsTaken($data['email'])) {
            return (new RedirectResponse('/register', 303))->withErrors(
                $request->session(),
                ['email' => ['That email address is already registered.']],
                ['name' => $data['name'], 'email' => $data['email']],
            );
        }

        // Only name and email are fillable; the password goes through the
        // hashing setter rather than being mass assigned.
        $user = new User(['name' => $data['name'], 'email' => $data['email']]);
        $user->setPassword($data['password']);
        $user->save();

        $this->auth->login($user);

        return (new RedirectResponse('/dashboard', 303))
            ->with($request->session(), 'success', 'Your account is ready.');
    }
}
