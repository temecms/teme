<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * The `updates` settings group, Settings › Updates: the Dokploy API key the updater uses to change and deploy
 * this site's service. Kept encrypted and read by the updater alone; a key set on the server as TEME_DOKPLOY_TOKEN
 * wins. Saving it needs the updates permission and the password given again.
 */
final class UpdatesSettings
{
    public const string GROUP = 'updates';

    public const string DOKPLOY_API_KEY = 'dokploy_api_key';

    /**
     * The group, as its provider registers it.
     */
    public static function group(): SettingsGroup
    {
        return new SettingsGroup(
            key: self::GROUP,
            label: 'Updates',
            description: 'How Teme reaches the platform that runs it, so Tools › Updates can update the site.',
            section: 'site',
            ability: 'updates.manage',
            settings: [
                Setting::secret(self::DOKPLOY_API_KEY, 'Dokploy API key', 'Lets Teme change and deploy this site’s service on Dokploy when you update from Tools › Updates. In Dokploy, open your profile, make an API key, and paste it here. It is kept encrypted and never shown again. The server can set it instead, as TEME_DOKPLOY_TOKEN.', '1.0.0')
                    ->env('TEME_DOKPLOY_TOKEN'),
            ],
            since: '1.0.0',
            // The key can change and deploy every service the Dokploy account can reach.
            sensitive: true,
        );
    }
}
