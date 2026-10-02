<?php

declare(strict_types=1);

namespace Phpvin\Console;

/**
 * The parsed command line.
 *
 * Arguments are positional, options are `--name` or `--name=value`, and flags
 * are options without a value. No short-option guessing, no clustering: what
 * you typed is what you get.
 */
final class Input
{
    /**
     * @param list<string>          $arguments
     * @param array<string, string|bool> $options
     */
    public function __construct(
        public readonly string $command,
        private readonly array $arguments = [],
        private readonly array $options = [],
    ) {}

    /**
     * @param list<string> $argv Raw $argv, script name included.
     */
    public static function fromArgv(array $argv): self
    {
        array_shift($argv);

        $command = '';
        $arguments = [];
        $options = [];

        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                [$name, $value] = array_pad(explode('=', substr($token, 2), 2), 2, null);
                // A flag stores true purely for readability: flag() asks
                // isset(), which is true for any stored value.
                $options[$name] = $value ?? true; // mutation:ignore

                continue;
            }

            if ($command === '') {
                $command = $token;

                continue;
            }

            $arguments[] = $token;
        }

        return new self($command, $arguments, $options);
    }

    public function argument(int $position, ?string $default = null): ?string
    {
        return $this->arguments[$position] ?? $default;
    }

    /** @return list<string> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }
}
