<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Hub;

use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Site\SiteInfo;

/**
 * A route handler that asks for the settings reader and the site's facts,
 * and answers with the site's name and the plugin's hub.
 */
final class HubPage implements PublicRoute
{
    public function __construct(
        private readonly SettingsReader $settings,
        private readonly SiteInfo $site,
    ) {}

    public function handle(PublicRequest $request): PublicResponse
    {
        return new PublicResponse(200, 'text/plain; charset=UTF-8', $this->site->name().' '.$this->settings->string('plugin.hub', 'hub_url'));
    }
}
