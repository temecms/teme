<?php

/**
 * What an inbox answers: a status and any headers, never a detail of why.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Inbox;

final readonly class InboxAnswer
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers = [],
    ) {}
}
