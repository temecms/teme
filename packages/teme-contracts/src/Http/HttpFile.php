<?php

/**
 * One file in a multipart request through the outbound door: its field, its
 * name, its content type, and its bytes or a path the plugin owns.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Http;

use InvalidArgumentException;

/**
 * One file in a multipart request through the outbound door. It carries
 * either its bytes or the path of a file inside the plugin's own folder,
 * never both.
 *
 * @since 1.7.0
 */
final readonly class HttpFile
{
    /**
     * @param  string  $field  the form field the file is sent under, such as media
     * @param  string  $filename  the file's name as the other side sees it, such as photo.jpg
     * @param  string  $contentType  its content type, such as image/jpeg
     * @param  string|null  $bytes  the file's bytes, or null when $path says where they are
     * @param  string|null  $path  the path of a file inside the plugin's own folder, or null when $bytes holds them
     *
     * @throws InvalidArgumentException when neither or both of $bytes and $path are given, or a name is empty
     *
     * @since 1.7.0
     */
    public function __construct(
        public string $field,
        public string $filename,
        public string $contentType,
        public ?string $bytes = null,
        public ?string $path = null,
    ) {
        if (($bytes === null) === ($path === null)) {
            throw new InvalidArgumentException('A file carries its bytes or a path, one of them.');
        }

        if ($field === '' || $filename === '' || $contentType === '') {
            throw new InvalidArgumentException('A file needs a field, a name and a content type.');
        }
    }
}
