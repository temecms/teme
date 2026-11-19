<?php

/**
 * What a declared public route's handler sees of one request: plain values, no
 * framework types (rule 5).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Routes;

/**
 * What a declared public route's handler sees of one request: plain values,
 * no framework types (rule 5).
 *
 * @since 1.0.0
 */
final readonly class PublicRequest
{
    /**
     * @param  array<string, string>  $parameters  path parameters
     * @param  array<string, string>  $query
     * @param  array<string, string>  $headers  lower-cased names
     *
     * @since 1.0.0
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $parameters,
        public array $query,
        public array $headers,
        public string $rawBody,
        public string $clientAddress,
    ) {}
}
