<?php

/**
 * What Check key found when it asked the provider (I-315): the key works, the
 * provider refused it, the provider couldn't be reached, or there is no key.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

enum AiKeyCheck: string
{
    case Works = 'works';
    case Refused = 'refused';
    case Unreachable = 'unreachable';
    case Missing = 'missing';

    /**
     * The one sentence Settings › AI shows for it.
     */
    public function sentence(string $provider): string
    {
        return match ($this) {
            self::Works => 'The key works.',
            self::Refused => "{$provider} didn’t accept this key.",
            self::Unreachable => "{$provider} couldn’t be reached to check the key. Try again in a minute.",
            self::Missing => 'There’s no key to check.',
        };
    }
}
