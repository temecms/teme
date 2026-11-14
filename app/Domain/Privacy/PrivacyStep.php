<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

/**
 * What one step through a privacy request did: whether the request ended,
 * the holder it just asked, and how it ended. The request's ID is last, for
 * the step's own work after commit.
 */
final readonly class PrivacyStep
{
    /**
     * @param  bool  $done  the request ended with this step
     * @param  string|null  $holder  the key of the holder just asked, or null when the step finished the request
     * @param  string|null  $ended  'done' or 'failed' once the request ended, else null
     * @param  string  $requestId  the request stepped
     */
    public function __construct(
        public bool $done,
        public ?string $holder,
        public ?string $ended = null,
        public string $requestId = '',
    ) {}
}
