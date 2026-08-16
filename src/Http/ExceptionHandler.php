<?php

declare(strict_types=1);

namespace Phpvin\Http;

use Phpvin\Validation\ValidationException;
use Phpvin\View\ViewFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Turns anything thrown during a request into a Response.
 *
 * With debug off, the client is told the status code and nothing else. Stack
 * traces and exception messages only ever reach the browser in debug mode.
 */
class ExceptionHandler
{
    /**
     * @param bool $preferJson Set for API-only applications, where an HTML
     *                         error page would never be the right answer.
     */
    public function __construct(
        private readonly bool $debug = false,
        private readonly ?ViewFactory $views = null,
        private readonly bool $preferJson = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            return $this->renderValidation($e, $request);
        }

        $status = $e instanceof HttpException ? $e->status() : 500;

        $this->report($e, $request, $status);

        if ($this->preferJson || $request->wantsJson()) {
            return new JsonResponse($this->payload($e, $status), $status);
        }

        return $this->renderHtml($e, $request, $status);
    }

    /**
     * Record the failure.
     *
     * 5xx means we broke something and somebody needs to see it. 4xx is the
     * client's mistake and would drown the log in bot traffic, so it is
     * recorded at info and filtered out by default.
     */
    protected function report(Throwable $e, Request $request, int $status): void
    {
        $this->logger?->log(
            $status >= 500 ? LogLevel::ERROR : LogLevel::INFO,
            sprintf('%s %s failed with %d', $request->method, $request->path, $status),
            [
                'exception' => $e,
                'status' => $status,
                'method' => $request->method,
                'path' => $request->path,
                'ip' => $request->ip(),
            ],
        );
    }

    /**
     * A failed validation redirects back with the errors and the submitted
     * input flashed, which is what a server-rendered form wants. API clients
     * get 422 with a JSON body instead.
     */
    protected function renderValidation(ValidationException $e, Request $request): Response
    {
        if ($this->preferJson || $request->wantsJson() || ! $request->hasSession()) {
            return new JsonResponse(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return (new RedirectResponse($request->header('referer') ?? $request->path, 303))
            ->withErrors($request->session(), $e->errors(), $e->input());
    }

    protected function renderHtml(Throwable $e, Request $request, int $status): Response
    {
        $template = "errors/$status";

        try {
            if ($this->views?->exists($template)) {
                return $this->views->response($template, [
                    'status' => $status,
                    'message' => $this->message($e, $status),
                    'exception' => $this->debug ? $e : null,
                ], $status);
            }
        } catch (Throwable $renderFailure) {
            // The error page is the last thing standing. If it is broken too,
            // say so in the log and fall back to markup that cannot fail.
            // Never let a second exception escape from here.
            $this->logger?->error('The error template [{template}] failed to render.', [
                'template' => $template,
                'exception' => $renderFailure,
                'original' => $e,
            ]);
        }

        return Response::html($this->fallbackPage($e, $status), $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Throwable $e, int $status): array
    {
        $payload = ['message' => $this->message($e, $status), 'status' => $status];

        if ($this->debug && $status >= 500) {
            $payload['exception'] = $e::class;
            $payload['file'] = $e->getFile() . ':' . $e->getLine();
            $payload['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 15);
        }

        return $payload;
    }

    /**
     * A 4xx message describes the client's mistake and is safe to show. A 5xx
     * message describes ours, and is withheld unless debugging.
     */
    protected function message(Throwable $e, int $status): string
    {
        if ($status < 500 || $this->debug) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'Something went wrong.';
        }

        return 'Something went wrong on our end.';
    }

    private function fallbackPage(Throwable $e, int $status): string
    {
        $title = htmlspecialchars((string) $status, ENT_QUOTES);
        $message = htmlspecialchars($this->message($e, $status), ENT_QUOTES);

        $detail = '';

        if ($this->debug && $status >= 500) {
            $detail = sprintf(
                '<p class="where">%s<br><span>%s:%d</span></p><pre>%s</pre>',
                htmlspecialchars($e::class, ENT_QUOTES),
                htmlspecialchars($e->getFile(), ENT_QUOTES),
                $e->getLine(),
                htmlspecialchars($e->getTraceAsString(), ENT_QUOTES),
            );
        }

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>$title</title>
        <style>
          :root { color-scheme: light dark; }
          body { margin:0; min-height:100vh; display:grid; place-items:center;
                 font:16px/1.6 ui-sans-serif,system-ui,-apple-system,sans-serif;
                 background:#fafafa; color:#18181b; padding:2rem; }
          main { max-width:52rem; width:100%; }
          h1 { font-size:4rem; margin:0; letter-spacing:-.04em; }
          p { margin:.5rem 0 0; color:#52525b; }
          .where span { color:#a1a1aa; font-size:.85em; }
          pre { margin-top:1.5rem; padding:1rem; overflow-x:auto; font-size:.8rem;
                background:#f4f4f5; border-radius:.5rem; color:#3f3f46; }
          @media (prefers-color-scheme: dark) {
            body { background:#09090b; color:#fafafa; }
            p { color:#a1a1aa; }
            pre { background:#18181b; color:#d4d4d8; }
          }
        </style>
        </head>
        <body><main><h1>$title</h1><p>$message</p>$detail</main></body>
        </html>
        HTML;
    }
}
