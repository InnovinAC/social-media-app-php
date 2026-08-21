<?php

declare(strict_types=1);

namespace Phpvin\Http;

use LogicException;

/**
 * An immutable snapshot of the incoming request.
 *
 * Nothing here reads a superglobal after construction, so a Request built by
 * hand in a test behaves exactly like one built from a real request.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     * @param array<string, string> $routeParameters
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly array $server = [],
        public readonly array $headers = [],
        public readonly array $routeParameters = [],
        private readonly ?Session $session = null,
    ) {}

    public static function fromGlobals(?Session $session = null): self
    {
        $server = $_SERVER;
        $body = $_POST;

        $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        return new self(
            method: self::resolveMethod($server, $body),
            path: '/' . trim($path, '/'),
            query: $_GET,
            body: $body,
            cookies: $_COOKIE,
            files: $_FILES,
            server: $server,
            headers: self::headersFromServer($server),
            session: $session,
        );
    }

    /**
     * Build a request directly. Handy in tests and for sub-requests.
     *
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    public static function create(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $headers = [],
        ?Session $session = null,
    ): self {
        return new self(
            method: strtoupper($method),
            path: '/' . trim(parse_url($path, PHP_URL_PATH) ?: '/', '/'),
            query: $query,
            body: $body,
            headers: $headers,
            session: $session,
        );
    }

    /**
     * Body first, then query string. Use query()/post() when the distinction
     * matters for security.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [...$this->query, ...$this->body];
    }

    /**
     * Pull a whitelisted subset of the input. Keys that were not submitted
     * are omitted rather than returned as null.
     *
     * @param  list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /**
     * A single uploaded file, or null when the field was absent.
     */
    public function file(string $key): ?UploadedFile
    {
        $entry = $this->files[$key] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $normalised = UploadedFile::normalise($entry);

        return is_array($normalised) ? ($normalised[0] ?? null) : $normalised;
    }

    /**
     * Every file submitted under an array field like `photos[]`.
     *
     * @return list<UploadedFile>
     */
    public function fileList(string $key): array
    {
        $entry = $this->files[$key] ?? null;

        if (! is_array($entry)) {
            return [];
        }

        $normalised = UploadedFile::normalise($entry);

        return is_array($normalised) ? $normalised : [$normalised];
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key)?->isValid() ?? false;
    }

    public function routeParameter(string $name, ?string $default = null): ?string
    {
        return $this->routeParameters[$name] ?? $default;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isReading(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true); // mutation:ignore strict flag is equivalent for an array of string literals
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    /**
     * True when the client would rather have JSON than HTML.
     */
    public function wantsJson(): bool
    {
        return $this->isAjax()
            || str_contains($this->header('accept') ?? '', 'application/json')
            || str_contains($this->header('content-type') ?? '', 'application/json');
    }

    public function ip(): ?string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }

    public function session(): Session
    {
        return $this->session ?? throw new LogicException(
            'No session is attached to this request. Pass one to Request::create() or Request::fromGlobals().',
        );
    }

    public function hasSession(): bool
    {
        return $this->session !== null;
    }

    /**
     * @param array<string, string> $parameters
     */
    public function withRouteParameters(array $parameters): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            query: $this->query,
            body: $this->body,
            cookies: $this->cookies,
            files: $this->files,
            server: $this->server,
            headers: $this->headers,
            routeParameters: $parameters,
            session: $this->session,
        );
    }

    /**
     * HTML forms can only submit GET and POST, so honour a `_method` field on
     * POST bodies the way every server-rendered stack has since Rails.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $body
     */
    private static function resolveMethod(array $server, array $body): string
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        if ($method !== 'POST') {
            return $method;
        }

        $override = strtoupper((string) ($body['_method'] ?? ''));

        return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : 'POST'; // mutation:ignore strict flag is equivalent for an array of string literals
    }

    /**
     * @param  array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) { // mutation:ignore strict flag is equivalent for an array of string literals
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        return $headers;
    }
}
