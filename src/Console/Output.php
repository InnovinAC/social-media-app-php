<?php

declare(strict_types=1);

namespace Phpvin\Console;

/**
 * Console output.
 *
 * Writes to a stream rather than echoing, so a test can read back exactly what
 * a command printed. Colour is dropped when the stream is not a terminal, so
 * piping to a file does not fill it with escape codes.
 */
final class Output
{
    private const COLOURS = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'grey' => "\033[90m",
        'bold' => "\033[1m",
    ];

    /** @var resource */
    private $stream;

    private readonly bool $decorated;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null, ?bool $decorated = null)
    {
        $this->stream = $stream ?? STDOUT;
        $this->decorated = $decorated ?? self::detectDecoration($this->stream);
    }

    /**
     * Whether to colour output for this stream.
     *
     * NO_COLOR wins outright: the convention is that setting it at all, to
     * any value, turns colour off. Otherwise it comes down to whether anything
     * is there to read the escape codes.
     *
     * @param resource $stream
     */
    public static function detectDecoration($stream): bool
    {
        return ! self::colourIsDisabled() && stream_isatty($stream);
    }

    /**
     * Whether NO_COLOR is set.
     *
     * Split out because it is the only half a test can observe: whether the
     * other half is a terminal depends on how the suite was launched.
     */
    public static function colourIsDisabled(): bool
    {
        return getenv('NO_COLOR') !== false;
    }

    public function write(string $text): void
    {
        fwrite($this->stream, $text);
    }

    public function line(string $text = ''): void
    {
        $this->write($text . "\n");
    }

    public function success(string $text): void
    {
        $this->line($this->paint($text, 'green'));
    }

    public function error(string $text): void
    {
        $this->line($this->paint($text, 'red'));
    }

    public function warn(string $text): void
    {
        $this->line($this->paint($text, 'yellow'));
    }

    public function muted(string $text): void
    {
        $this->line($this->paint($text, 'grey'));
    }

    public function heading(string $text): void
    {
        $this->line($this->paint($text, 'bold'));
    }

    public function paint(string $text, string $colour): string
    {
        if (! $this->decorated || ! isset(self::COLOURS[$colour])) {
            return $text;
        }

        return self::COLOURS[$colour] . $text . "\033[0m";
    }

    /**
     * Render rows in aligned columns.
     *
     * Widths come from the content, measured without colour codes so a painted
     * cell still lines up.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map($this->width(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, $this->width($cell));
            }
        }

        $this->line($this->paint($this->row($headers, $widths), 'bold'));

        foreach ($rows as $row) {
            $this->line($this->row($row, $widths));
        }
    }

    /**
     * @param list<string> $cells
     * @param list<int>    $widths
     */
    private function row(array $cells, array $widths): string
    {
        $out = [];

        foreach ($cells as $column => $cell) {
            $padding = str_repeat(' ', max(0, ($widths[$column] ?? 0) - $this->width($cell)));
            $out[] = $cell . $padding;
        }

        return rtrim('  ' . implode('  ', $out));
    }

    /** Visible width, ignoring colour escapes. */
    private function width(string $text): int
    {
        return mb_strlen((string) preg_replace('/\033\[[0-9;]*m/', '', $text));
    }
}
