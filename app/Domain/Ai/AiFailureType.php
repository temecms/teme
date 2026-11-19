<?php

/**
 * Why a request failed, in five types (I-314), and the one plain sentence
 * each that people read, wherever a failure is shown. Never the service's
 * own words.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

enum AiFailureType: string
{
    case Unavailable = 'unavailable';
    case RateLimited = 'rate-limited';
    case Refused = 'refused';
    case InvalidOutput = 'invalid-output';
    case Timeout = 'timeout';

    public function sentence(): string
    {
        return match ($this) {
            self::Unavailable => 'The AI service couldn’t be reached.',
            self::RateLimited => 'The AI service is busy and asked Teme to wait.',
            self::Refused => 'The AI service declined to answer.',
            self::InvalidOutput => 'The AI service’s answer couldn’t be used.',
            self::Timeout => 'The AI service took too long to answer.',
        };
    }

    /**
     * Whether a task's fallback model is asked, once: only when the service
     * couldn't answer at all or in time, never when it answered badly or said
     * no. The caller gives the fallback only the time that is left (I-1321).
     */
    public function fallsBack(): bool
    {
        return $this === self::Unavailable || $this === self::RateLimited || $this === self::Timeout;
    }
}
