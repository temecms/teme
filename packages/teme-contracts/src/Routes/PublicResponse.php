<?php

declare(strict_types=1);

namespace Teme\Contracts\Routes;

/**
 * What a declared public route's handler answers: status, type, body, and
 * extra headers from Teme's allow-list. Cache-Control is always Teme's.
 *
 * @since 0.8.0
 */
final readonly class PublicResponse
{
    /** Headers a handler may set; anything else is dropped. @since 0.8.0 */
    public const array HEADER_ALLOW_LIST = ['content-language', 'link', 'location', 'x-robots-tag'];

    /**
     * @param  array<string, string>  $headers
     *
     * @since 0.8.0
     */
    public function __construct(
        public int $status,
        public string $contentType,
        public string $body,
        public array $headers = [],
    ) {}
}
