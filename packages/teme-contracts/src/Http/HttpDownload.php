<?php

/**
 * A file a plugin downloaded: where it is, how big it is and what the answer
 * said about it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Http;

/**
 * @since next
 */
final readonly class HttpDownload
{
    /**
     * @param  string  $path  the file, inside the plugin's own folder
     * @param  int  $bytes  its size
     * @param  int  $status  the final answer's status, after any redirects
     * @param  string  $contentType  the final answer's content type, empty when it sent none
     *
     * @since next
     */
    public function __construct(
        public string $path,
        public int $bytes,
        public int $status,
        public string $contentType,
    ) {}
}
