<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use Teme\Contracts\Settings\SecretReader;
use Throwable;

/**
 * Where the updater finds the Dokploy API key: TEME_DOKPLOY_TOKEN when the environment sets one, else the key
 * saved in Settings › Updates (the secret `dokploy_api_key` of the `updates` group). It is read when a call is
 * made and never kept.
 */
final class DokployToken
{
    /** The setting the key is saved in, as the Updates settings page declares it. */
    public const string GROUP = 'updates';

    public const string KEY = 'dokploy_api_key';

    /**
     * Returns the key, or null when none is set anywhere, or Settings doesn't know the setting yet.
     */
    public static function value(): ?string
    {
        $fromEnvironment = config('teme.updater.dokploy.token');

        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            return $fromEnvironment;
        }

        try {
            $saved = app(SecretReader::class)->secret(self::GROUP, self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_string($saved) && $saved !== '' ? $saved : null;
    }
}
