<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Inbox;

/**
 * What an inbox answers: a status and any headers, never a detail of why.
 */
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
