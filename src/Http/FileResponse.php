<?php

declare(strict_types=1);

namespace Phpvin\Http;

use RuntimeException;

/**
 * Send a file to the client.
 *
 *     return FileResponse::download('/var/exports/report.csv');
 *     return FileResponse::inline('/var/uploads/plan.pdf', 'The Plan.pdf');
 *
 * The body is streamed rather than read into memory, so a 2 GB export costs
 * the same as a 2 KB one. The filename is quoted and also sent RFC 5987 encoded,
 * because a name with a comma or a non-ASCII character otherwise truncates the
 * header or, worse, injects into it.
 */
final class FileResponse extends Response
{
    private const CHUNK = 8192;

    private function __construct(
        private readonly string $path,
        string $disposition,
        ?string $name,
        ?string $contentType,
    ) {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("There is no readable file at [{$this->path}].");
        }

        $name ??= basename($this->path);

        parent::__construct('', 200, [
            'Content-Type' => $contentType ?? self::detectType($this->path),
            'Content-Length' => (string) (filesize($this->path) ?: 0),
            'Content-Disposition' => self::disposition($disposition, $name),
        ]);
    }

    /** Prompt a save dialog. */
    public static function download(string $path, ?string $name = null, ?string $contentType = null): self
    {
        return new self($path, 'attachment', $name, $contentType);
    }

    /** Show it in the browser where the browser can. */
    public static function inline(string $path, ?string $name = null, ?string $contentType = null): self
    {
        return new self($path, 'inline', $name, $contentType);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Write the file to output, a chunk at a time.
     *
     * Separate from send() so it can be exercised inside a test's own output
     * buffer; send() tears every buffer down, which is correct in a request
     * and hostile everywhere else.
     */
    public function stream(): void
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) { // mutation:ignore the constructor already rejected an unreadable path
            return;
        }

        while (! feof($handle)) {
            echo fread($handle, self::CHUNK);
        }

        fclose($handle);
    }

    public function send(): void
    {
        $this->sendHeaders();

        // Anything already buffered would be sent before the file and corrupt
        // it, so the buffers go first.
        while (ob_get_level() > 0) { // mutation:ignore requires a live SAPI
            ob_end_flush();
        }

        $this->stream();
        flush();
    }

    private function sendHeaders(): void
    {
        if (headers_sent()) { // mutation:ignore requires a live SAPI
            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($this->normaliseHeaderName($name) . ': ' . $value, true); // mutation:ignore requires a live SAPI
        }
    }

    /**
     * Build a Content-Disposition that survives an awkward filename.
     */
    public static function disposition(string $type, string $name): string
    {
        // Strip any directory component and anything that could break out of
        // the quoted string or add another header line.
        $name = basename($name);
        $name = (string) preg_replace('/[\r\n"\\\\]/', '', $name);

        // Character-wise, so "résumé" falls back to "r_sum_" rather than
        // "r__sum__" (é is two bytes). Falls back to a byte-wise pass if the
        // name is not valid UTF-8, where the /u pattern would simply fail.
        $ascii = preg_replace('/[^\x20-\x7E]/u', '_', $name)
            ?? (string) preg_replace('/[^\x20-\x7E]/', '_', $name);

        if ($ascii === '') {
            $ascii = 'download';
        }

        // Both forms: the quoted one for old clients, the encoded one for
        // everything since 2010.
        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $type,
            $ascii,
            rawurlencode($name),
        );
    }

    private static function detectType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) { // mutation:ignore finfo_open only fails if the extension is broken
                $type = finfo_file($finfo, $path);
                finfo_close($finfo);

                if ($type !== false) { // mutation:ignore finfo_file only fails on an unreadable path, rejected in the constructor
                    return $type;
                }
            }
        }

        return 'application/octet-stream';
    }
}
