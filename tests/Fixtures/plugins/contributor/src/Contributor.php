<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Contributor;

use RuntimeException;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Hooks\Subscription;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusCheck;
use Teme\Contracts\Status\StatusNotice;

/**
 * A plugin built only against teme/contracts, as a real one is: it tells a
 * workspace outside the site when an item is published or restored, sends
 * an unpublished item's address to a host its consent no longer covers,
 * fails when an item is trashed, and asks for its token in a banner.
 */
final class Contributor implements ChecksStatus, Plugin
{
    /** @var array<string, Subscription> each subscription the dispatcher gave it, by its hook's short name */
    public array $subscriptions = [];

    public function __construct(
        private readonly SettingsReader $settings,
        private readonly SecretReader $secrets,
        private readonly OutboundHttp $http,
    ) {}

    public function register(Hooks $hooks): void
    {
        $this->subscriptions['ContentPublished'] = $hooks->on(ContentPublished::class, function (ContentPublished $published): void {
            $this->http->postJson('https://api.example.com/published', [
                'id' => $published->item->id,
                'status' => $published->item->status,
                'project' => $this->settings->get('plugin.contributor', 'project'),
            ], ['Authorization' => 'Bearer '.$this->secrets->secret('plugin.contributor', 'token')]);
        });

        $this->subscriptions['ContentUnpublished'] = $hooks->on(ContentUnpublished::class, function (ContentUnpublished $unpublished): void {
            $this->http->postJson('https://other.example.com/unpublished', ['id' => $unpublished->item->id]);
        });

        $this->subscriptions['ContentTrashed'] = $hooks->on(ContentTrashed::class, function (ContentTrashed $trashed): void {
            throw new RuntimeException('Contributor couldn’t tell its workspace.');
        });

        $this->subscriptions['ContentRestored'] = $hooks->on(ContentRestored::class, function (ContentRestored $restored): void {
            $this->http->postJson('https://api.example.com/restored', ['id' => $restored->item->id]);
        });
    }

    public function statusChecks(): array
    {
        return [new readonly class($this->secrets) implements StatusCheck
        {
            public function __construct(private SecretReader $secrets) {}

            public function notice(): ?StatusNotice
            {
                return $this->secrets->secret('plugin.contributor', 'token') === null
                    ? new StatusNotice('warning', 'Contributor needs its token.', true)
                    : null;
            }
        }];
    }
}
