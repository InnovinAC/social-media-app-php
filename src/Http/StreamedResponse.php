<?php

declare(strict_types=1);

namespace Phpvin\Http;

use Closure;
use Throwable;

/**
 * A response whose body is produced as it is sent.
 *
 *     return StreamedResponse::make(function (): void {
 *         $out = fopen('php://output', 'w');
 *
 *         foreach (Order::query()->get() as $order) {
 *             fputcsv($out, [$order->id, $order->total]);
 *         }
 *     }, headers: ['Content-Type' => 'text/csv']);
 *
 * Nothing is held in memory, so the size of the export stops mattering. The
 * trade is that the status and headers are gone the moment the first byte is
 * written, so an error halfway through cannot become a 500.
 */
final class StreamedResponse extends Response
{
    /** @var Closure(): void */
    private Closure $callback;

    /**
     * @param Closure(): void       $callback
     * @param array<string, string> $headers
     */
    public function __construct(Closure $callback, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);

        $this->callback = $callback;
    }

    /**
     * @param Closure(): void       $callback
     * @param array<string, string> $headers
     */
    public static function make(Closure $callback, int $status = 200, array $headers = []): self
    {
        return new self($callback, $status, $headers);
    }

    /**
     * Run the callback and capture what it writes.
     *
     * For tests: streaming is about not buffering, which is exactly what makes
     * a streamed response awkward to assert on otherwise.
     */
    public function capture(): string
    {
        ob_start();

        try {
            $this->stream();

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }

    /**
     * Run the callback, writing straight to output.
     */
    public function stream(): void
    {
        ($this->callback)();
    }

    public function send(): void
    {
        if (! headers_sent()) { // mutation:ignore requires a live SAPI
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($this->normaliseHeaderName($name) . ': ' . $value, true); // mutation:ignore requires a live SAPI
            }
        }

        while (ob_get_level() > 0) { // mutation:ignore requires a live SAPI
            ob_end_flush();
        }

        $this->stream();
    }
}
