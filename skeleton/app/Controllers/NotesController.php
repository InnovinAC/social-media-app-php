<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Note;
use App\Support\Auth;
use Phpvin\Http\Commands;
use Phpvin\Http\HttpException;
use Phpvin\Http\RedirectResponse;
use Phpvin\Http\Request;
use Phpvin\Http\Response;
use Phpvin\Validation\Validator;
use Phpvin\View\ViewFactory;

/**
 * The AJAX demo.
 *
 * A fragment response can only change one place on the page. These actions
 * return a Commands list instead, which changes several (insert the row, drop
 * the empty state, update the counter, refocus the input) in one round trip,
 * still without a line of page-specific JavaScript.
 *
 * With JavaScript off the same actions redirect, and everything still works.
 */
final class NotesController
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly Validator $validator,
        private readonly Auth $auth,
    ) {}

    public function store(Request $request): Response
    {
        $data = $this->validator->validate($request->all(), [
            'body' => 'required|max:200',
        ]);

        $note = new Note(['body' => $data['body']]);
        $note->user_id = $this->auth->id();
        $note->save();

        if (! $request->isAjax()) {
            return new RedirectResponse('/dashboard', 303);
        }

        // toResponse() rather than returning the builder, so the status says
        // a resource was created instead of defaulting to 200.
        return Commands::make()
            ->prepend('#notes', $this->views->render('notes/row', ['note' => $note]))
            ->remove('#empty-state')
            ->text('#note-count', (string) $this->count())
            ->focus('input[name=body]')
            ->trigger('note:added', ['id' => $note->key()])
            ->toResponse(201);
    }

    public function destroy(Request $request, int $id): Response|Commands
    {
        $note = Note::find($id);

        // Existence and ownership answered the same way, so probing for other
        // people's note ids tells you nothing.
        if ($note === null || ! $note->isOwnedBy((int) $this->auth->id())) {
            throw HttpException::notFound('No such note.');
        }

        $note->delete();

        if (! $request->isAjax()) {
            return new RedirectResponse('/dashboard', 303);
        }

        $remaining = $this->count();

        $commands = Commands::make()
            ->remove('#note-' . $id)
            ->text('#note-count', (string) $remaining);

        if ($remaining === 0) {
            $commands->append('#notes', '<li class="empty" id="empty-state">Nothing yet.</li>');
        }

        return $commands->trigger('note:removed', ['id' => $id]);
    }

    private function count(): int
    {
        return Note::query()->where('user_id', '=', $this->auth->id())->count();
    }
}
