<?php

/**
 * What a remote host answered: its status, its body and its headers.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Http;

use JsonException;

/**
 * What a remote host answered: its status, its body and its headers.
 *
 * @since 0.5.0
 */
final readonly class HttpAnswer
{
    /**
     * @param  array<string, list<string>>  $headers
     *
     * @since 0.5.0
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
    ) {}

    /**
     * The body decoded as JSON.
     *
     * @throws JsonException when the body is not JSON
     *
     * @since 0.5.0
     */
    public function json(): mixed
    {
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }
}
