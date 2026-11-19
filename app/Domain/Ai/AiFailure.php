<?php

/**
 * A request that failed, by type only (I-314). It is made by its five named
 * constructors and carries no message of the service's and no earlier
 * exception, because a service's error text can quote the prompt, and from
 * here it could reach a log, a queue row or a browser. A provider maps
 * its library's errors to these and lets the originals go. An answer that
 * was billed but can't be used carries its usage, so its cost is counted
 * (I-1322).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use RuntimeException;

final class AiFailure extends RuntimeException
{
    private function __construct(
        public readonly AiFailureType $type,
        public readonly ?int $retryAfterSeconds,
        public readonly ?AiUsage $usage = null,
    ) {
        parent::__construct(match ($type) {
            AiFailureType::Unavailable => 'AI provider unavailable.',
            AiFailureType::RateLimited => 'AI provider rate-limited.',
            AiFailureType::Refused => 'AI provider refused the request.',
            AiFailureType::InvalidOutput => 'AI provider\'s output failed its check.',
            AiFailureType::Timeout => 'AI provider timed out.',
        });
    }

    public static function unavailable(): self
    {
        return new self(AiFailureType::Unavailable, null);
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        return new self(AiFailureType::RateLimited, $retryAfterSeconds);
    }

    public static function refused(?AiUsage $usage = null): self
    {
        return new self(AiFailureType::Refused, null, $usage);
    }

    public static function invalidOutput(?AiUsage $usage = null): self
    {
        return new self(AiFailureType::InvalidOutput, null, $usage);
    }

    public static function timedOut(): self
    {
        return new self(AiFailureType::Timeout, null);
    }
}
