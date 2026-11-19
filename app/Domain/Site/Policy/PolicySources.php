<?php

/**
 * The origins a page's content security policy allows beside its own
 * (I-3375): the public media disk's, the asset address's and the embed
 * providers' frames, worked out from configuration, shared by the public
 * site's policy and the admin's signed-in preview.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Policy;

use App\Domain\Media\MediaDisks;

final class PolicySources
{
    /**
     * A URL's origin, as scheme://host and any port, in lower case; null for
     * one without a scheme and a host.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * The public media disk's origin when it isn't $here, the scheme and
     * host the page was asked at; [] when it is.
     *
     * @return list<string>
     */
    public static function media(string $here): array
    {
        return self::elsewhere(self::origin(MediaDisks::public()->url('probe')), $here);
    }

    /**
     * Each embed provider's frame origin, from config/embeds.php, once.
     *
     * @return list<string>
     */
    public static function embedFrames(): array
    {
        $frames = [];

        foreach ((array) config('embeds.providers', []) as $provider) {
            $frame = self::origin((string) ($provider['frame'] ?? ''));

            if ($frame !== null && ! in_array($frame, $frames, true)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * ASSET_URL's origin when it is set and isn't $here, since theme assets,
     * fonts and card styles are addressed through it; [] otherwise.
     *
     * @return list<string>
     */
    public static function asset(string $here): array
    {
        $asset = (string) config('app.asset_url');

        return $asset === '' ? [] : self::elsewhere(self::origin($asset), $here);
    }

    /**
     * @return list<string>
     */
    private static function elsewhere(?string $origin, string $here): array
    {
        return $origin === null || $origin === strtolower($here) ? [] : [$origin];
    }
}
