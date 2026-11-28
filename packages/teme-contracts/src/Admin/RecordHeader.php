<?php

/**
 * What a record's page header shows under the title: an optional status
 * pill and up to three plain meta lines.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

use InvalidArgumentException;

/**
 * What a record's page header shows under the title: an optional status
 * pill and up to three plain meta lines. Null from the plugin means title
 * only; a failure to read one never becomes an error page.
 *
 * @since next
 */
final readonly class RecordHeader
{
    /**
     * The tones a status pill may take, matching list badges.
     *
     * @since next
     */
    public const array TONES = ['ok', 'neutral', 'info', 'warn', 'danger'];

    /**
     * @param  list<string>  $meta  up to three plain lines; core joins them with " · "
     *
     * @throws InvalidArgumentException for an unknown tone or more than three meta lines
     *
     * @since next
     */
    public function __construct(
        public ?string $status = null,
        public string $tone = 'neutral',
        public array $meta = [],
    ) {
        if (! in_array($tone, self::TONES, true)) {
            throw new InvalidArgumentException('A record header’s tone must be ok, neutral, info, warn or danger.');
        }

        if (count($meta) > 3) {
            throw new InvalidArgumentException('A record header has at most three meta lines.');
        }
    }
}
