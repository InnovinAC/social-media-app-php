<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Note;
use App\Support\Auth;
use Phpvin\Http\Response;
use Phpvin\View\ViewFactory;

final class HomeController
{
    public function __construct(private readonly ViewFactory $views) {}

    public function index(): Response
    {
        return $this->views->response('home');
    }

    public function dashboard(Auth $auth): Response
    {
        return $this->views->response('dashboard', [
            'signedInAs' => $auth->user()?->email,
            'notes' => Note::forUser((int) $auth->id()),
        ]);
    }
}
