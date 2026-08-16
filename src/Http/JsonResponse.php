<?php

declare(strict_types=1);

namespace Phpvin\Http;

use JsonException;

class JsonResponse extends Response
{
    /**
     * @param array<string, string> $headers
     *
     * @throws JsonException when the payload cannot be encoded
     */
    public function __construct(mixed $data = null, int $status = 200, array $headers = [])
    {
        parent::__construct(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            $headers + ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }
}
