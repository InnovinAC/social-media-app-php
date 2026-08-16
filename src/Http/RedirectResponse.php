<?php

declare(strict_types=1);

namespace Phpvin\Http;

class RedirectResponse extends Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly string $location,
        int $status = 302,
        array $headers = [],
    ) {
        parent::__construct('', $status, $headers + ['Location' => $location]);
    }

    public function location(): string
    {
        return $this->location;
    }

    /**
     * Attach a one-request flash message, e.g. after a successful form post.
     */
    public function with(Session $session, string $key, mixed $value): static
    {
        $session->flash($key, $value);

        return $this;
    }

    /**
     * Flash validation errors and the submitted input so a form can be
     * redrawn with the user's data still in it.
     *
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>        $old
     */
    public function withErrors(Session $session, array $errors, array $old = []): static
    {
        $session->flash('errors', $errors);
        $session->flash('old', $old);

        return $this;
    }
}
