<?php

/**
 * What a redirect answers (I-297): four ways of sending readers on, and two
 * of sending them nowhere, gone for good or withheld for legal reasons.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects;

enum RedirectStatus: int
{
    case MovedPermanently = 301;
    case Found = 302;
    case TemporaryRedirect = 307;
    case PermanentRedirect = 308;
    case Gone = 410;
    case UnavailableForLegalReasons = 451;

    /** The status's name, as the redirect list shows it (I-872). */
    public function label(): string
    {
        return match ($this) {
            self::MovedPermanently => __('Moved permanently'),
            self::Found => __('Found (temporary)'),
            self::TemporaryRedirect => __('Temporary redirect'),
            self::PermanentRedirect => __('Permanent redirect'),
            self::Gone => __('Gone'),
            self::UnavailableForLegalReasons => __('Unavailable for legal reasons'),
        };
    }

    /** Whether it answers with no target: 410 or 451. */
    public function sendsNowhere(): bool
    {
        return $this->value >= 400;
    }
}
