<?php

declare(strict_types=1);

namespace Phpvin\Validation;

use RuntimeException;

class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed>        $input
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $input = [],
    ) {
        parent::__construct('The submitted data is invalid.', 422);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        return $this->input;
    }

    public function first(): ?string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return null;
    }
}
