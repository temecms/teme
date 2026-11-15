<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

/**
 * What one try of a delivery came to: sent; refused for good; gone (the
 * remote account no longer exists); a failure that may pass, to try again;
 * or skipped without a try, with why.
 */
final readonly class DeliveryOutcome
{
    public const string SENT = 'sent';

    public const string REFUSED = 'refused';

    public const string GONE = 'gone';

    public const string RETRY = 'retry';

    public const string SKIPPED = 'skipped';

    public function __construct(
        public string $kind,
        public ?int $status = null,
        public ?string $error = null,
    ) {}

    /**
     * The outcome an inbox's answer means (docs/plans/fediverse-publishing.md,
     * D3): 2xx sent, 410 gone, another 4xx refused, a 5xx to try again,
     * anything else refused.
     */
    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status >= 200 && $status < 300 => new self(self::SENT, $status),
            $status === 410 => new self(self::GONE, $status, 'The account is gone.'),
            $status >= 500 => new self(self::RETRY, $status, 'The server answered '.$status.'.'),
            default => new self(self::REFUSED, $status, 'The server answered '.$status.'.'),
        };
    }
}
