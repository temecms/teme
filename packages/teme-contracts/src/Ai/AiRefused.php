<?php

/**
 * Refused before sending (reason as AiStanding's, or no-person, no-permission,
 * in-transaction, no-task, too-many-tokens, no-schema, too-long), or failed
 * after (unavailable, rate-limited, invalid-output, timeout, refused).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Ai;

use RuntimeException;

/**
 * Refused before sending (reason as AiStanding's, or no-person, no-permission,
 * in-transaction, no-task, too-many-tokens, no-schema, too-long), or failed
 * after (unavailable, rate-limited, invalid-output, timeout, refused). Its
 * message is one plain sentence, never the AI service's own words.
 *
 * @since 1.0.0
 */
final class AiRefused extends RuntimeException
{
    /**
     * @since 1.0.0
     */
    public function __construct(public readonly string $reason, string $sentence)
    {
        parent::__construct($sentence);
    }
}
