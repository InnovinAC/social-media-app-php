<?php

declare(strict_types=1);

namespace Phpvin\Http;

use InvalidArgumentException;

/**
 * Controllers return one of these. Nothing in the framework echoes directly,
 * which is what makes a controller assertable in a unit test.
 */
class Response
{
    /** Header phpvin.js reads to fire client-side events. */
    public const TRIGGER_HEADER = 'X-Phpvin-Trigger';

    /** @var array<string, string> */
    protected array $headers = [];

    /** @var list<array{name: string, value: string, options: array<string, mixed>}> */
    protected array $cookies = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        protected string $body = '',
        protected int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Set a response header.
     *
     * A carriage return or newline in the value ends the header and starts
     * another one, so a single unvalidated value (a `Location` built from a
     * query parameter, a filename echoed into Content-Disposition) can append
     * a `Set-Cookie` of the attacker's choosing. PHP's own header() drops such
     * a call with a warning; that is a silently missing header rather than an
     * error, and it only protects the one SAPI path. Refusing here means the
     * response object never holds a value it cannot safely emit, whatever
     * eventually writes it out.
     *
     * @throws InvalidArgumentException on a malformed name or a value
     *                                   containing a control character
     */
    public function header(string $name, string $value): static
    {
        // RFC 7230 token. Anything outside it cannot appear before the colon.
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
            throw new InvalidArgumentException("[$name] is not a valid header name.");
        }

        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException(
                "The value for the [$name] header contains a line break or null byte.",
            );
        }

        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @param array<string, mixed> $options See setcookie()'s options array.
     */
    public function cookie(string $name, string $value, array $options = []): static
    {
        $this->cookies[] = [
            'name' => $name,
            'value' => $value,
            'options' => $options + [
                'httponly' => true,
                'samesite' => 'Lax',
                'path' => '/',
            ],
        ];

        return $this;
    }

    /**
     * Fire a jQuery event on the client when this response lands.
     *
     * Works on any response, including plain fragments, so a controller can
     * notify the page without switching to a command list.
     *
     * @param array<string, mixed> $detail
     */
    public function triggerClient(string $event, array $detail = []): static
    {
        $existing = json_decode($this->getHeader(self::TRIGGER_HEADER) ?? '{}', true);
        $events = is_array($existing) ? $existing : [];
        $events[$event] = $detail;

        return $this->header(self::TRIGGER_HEADER, json_encode($events, JSON_THROW_ON_ERROR));
    }

    /**
     * The cookies queued on this response.
     *
     * @return list<array{name: string, value: string, options: array<string, mixed>}>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * Replace a queued cookie's value in place.
     *
     * Used by EncryptCookies, which seals values after the controller has set
     * them so nothing downstream has to remember to.
     */
    public function replaceCookie(int $index, string $value): static
    {
        if (isset($this->cookies[$index])) {
            $this->cookies[$index]['value'] = $value;
        }

        return $this;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Write this response to the client. Called once, by the Application.
     */
    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                // The replace flag cannot be observed without inspecting real
                // headers, which a unit test has no access to.
                header($this->normaliseHeaderName($name) . ': ' . $value, true); // mutation:ignore
            }

            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }

        echo $this->body;
    }

    protected function normaliseHeaderName(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', $name)));
    }
}
