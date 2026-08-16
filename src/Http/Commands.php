<?php

declare(strict_types=1);

namespace Phpvin\Http;

use JsonSerializable;

/**
 * A list of operations for phpvin.js to run on the page.
 *
 * A fragment response can only change one place. This can change any number:
 *
 *     return Commands::make()
 *         ->prepend('#notes', $this->views->render('notes/row', ['note' => $note]))
 *         ->remove('#empty-state')
 *         ->text('#count', (string) $count)
 *         ->value('input[name=body]', '')
 *         ->trigger('note:added', ['id' => $note->key()])
 *         ->toResponse(201);
 *
 * Controllers may also just return the builder; the Application converts it.
 *
 * The escape hatch is `call()`, which invokes a command the page registered
 * with `phpvin.command(name, fn)`. A response can therefore run anything you
 * have written, and nothing you have not: there is no command that evaluates
 * code from the response body, on purpose.
 */
final class Commands implements JsonSerializable
{
    public const CONTENT_TYPE = 'application/vnd.phpvin.commands+json';

    /** @var list<array<string, mixed>> */
    private array $commands = [];

    public static function make(): self
    {
        return new self();
    }

    // --- inserting markup -------------------------------------------------

    public function append(string $selector, string $html): self
    {
        return $this->push('append', ['selector' => $selector, 'html' => $html]);
    }

    public function prepend(string $selector, string $html): self
    {
        return $this->push('prepend', ['selector' => $selector, 'html' => $html]);
    }

    /** Replace the element itself. */
    public function replace(string $selector, string $html): self
    {
        return $this->push('replace', ['selector' => $selector, 'html' => $html]);
    }

    /** Replace the element's contents. */
    public function inner(string $selector, string $html): self
    {
        return $this->push('inner', ['selector' => $selector, 'html' => $html]);
    }

    public function before(string $selector, string $html): self
    {
        return $this->push('before', ['selector' => $selector, 'html' => $html]);
    }

    public function after(string $selector, string $html): self
    {
        return $this->push('after', ['selector' => $selector, 'html' => $html]);
    }

    public function remove(string $selector): self
    {
        return $this->push('remove', ['selector' => $selector]);
    }

    // --- classes, attributes, content -------------------------------------

    public function addClass(string $selector, string $class): self
    {
        return $this->push('addClass', ['selector' => $selector, 'value' => $class]);
    }

    public function removeClass(string $selector, string $class): self
    {
        return $this->push('removeClass', ['selector' => $selector, 'value' => $class]);
    }

    public function toggleClass(string $selector, string $class): self
    {
        return $this->push('toggleClass', ['selector' => $selector, 'value' => $class]);
    }

    /**
     * Set an attribute, or remove it by passing null.
     */
    public function attr(string $selector, string $name, ?string $value): self
    {
        return $this->push('attr', ['selector' => $selector, 'name' => $name, 'value' => $value]);
    }

    /** Set text content. Escaping is the browser's job here, not yours. */
    public function text(string $selector, string $text): self
    {
        return $this->push('text', ['selector' => $selector, 'value' => $text]);
    }

    /** Set a form field's value. */
    public function value(string $selector, string $value): self
    {
        return $this->push('value', ['selector' => $selector, 'value' => $value]);
    }

    // --- page level -------------------------------------------------------

    public function focus(string $selector): self
    {
        return $this->push('focus', ['selector' => $selector]);
    }

    public function scrollTo(string $selector, bool $smooth = true): self
    {
        return $this->push('scrollTo', ['selector' => $selector, 'smooth' => $smooth]);
    }

    /**
     * Fire a jQuery event, on `$selector` or on the document.
     *
     * @param array<string, mixed> $detail
     */
    public function trigger(string $event, array $detail = [], ?string $selector = null): self
    {
        return $this->push('trigger', ['event' => $event, 'detail' => $detail, 'selector' => $selector]);
    }

    public function redirect(string $url): self
    {
        return $this->push('redirect', ['url' => $url]);
    }

    public function reload(): self
    {
        return $this->push('reload', []);
    }

    public function log(string $message): self
    {
        return $this->push('log', ['message' => $message]);
    }

    /**
     * Invoke a command the page registered with `phpvin.command()`.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments = []): self
    {
        return $this->push('call', ['name' => $name, 'arguments' => $arguments]);
    }

    // --- output -----------------------------------------------------------

    public function isEmpty(): bool
    {
        return $this->commands === [];
    }

    public function count(): int
    {
        return count($this->commands);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->commands;
    }

    public function toResponse(int $status = 200): Response
    {
        return new Response(
            json_encode($this->commands, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => self::CONTENT_TYPE],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->commands;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function push(string $command, array $payload): self
    {
        $this->commands[] = ['command' => $command] + $payload;

        return $this;
    }
}
