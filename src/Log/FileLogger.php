<?php

declare(strict_types=1);

namespace Phpvin\Log;

use DateTimeImmutable;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Appends log lines to a file.
 *
 * Deliberately small: enough that a fresh install records its own failures
 * without adding a dependency. Bind any other PSR-3 logger (Monolog and the
 * rest) over `LoggerInterface` and the framework uses that instead.
 *
 *     2026-08-07 20:41:02  ERROR  Unhandled RuntimeException  {"path":"/notes"}
 */
final class FileLogger extends AbstractLogger
{
    /** Severity order, least to most severe. */
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly int $threshold;

    public function __construct(
        private readonly string $path,
        string $minimumLevel = LogLevel::DEBUG,
    ) {
        $this->threshold = self::LEVELS[$minimumLevel]
            ?? throw new RuntimeException("Unknown log level [$minimumLevel].");
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->threshold) {
            return;
        }

        $line = sprintf(
            "%s  %-9s %s%s\n",
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            strtoupper((string) $level),
            $this->interpolate((string) $message, $context),
            $this->formatContext($context),
        );

        $this->write($line);
    }

    /**
     * Replace {placeholders} in the message with matching context values.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array &$context): string
    {
        foreach ($context as $key => $value) {
            $token = '{' . $key . '}';

            if (! str_contains($message, $token) || ! $this->isPrintable($value)) {
                continue;
            }

            $message = str_replace($token, (string) $value, $message);

            // Consumed by the message, so do not repeat it in the tail.
            unset($context[$key]);
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function formatContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $context[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'at' => $value->getFile() . ':' . $value->getLine(),
                ];
            }
        }

        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? '' : '  ' . $encoded;
    }

    private function isPrintable(mixed $value): bool
    {
        return $value === null || is_scalar($value) || $value instanceof Stringable;
    }

    private function write(string $line): void
    {
        $directory = dirname($this->path);

        // Silenced deliberately: the failure is handled on the next line, and
        // a raw warning from the logger would be noise on top of noise.
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            // Losing the log must not take the request down with it.
            error_log("phpvin: could not create the log directory [$directory]");

            return;
        }

        // LOCK_EX so concurrent workers do not interleave mid-line.
        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('phpvin: could not write to ' . $this->path . ': ' . rtrim($line));
        }
    }
}
