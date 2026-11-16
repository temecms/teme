<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Ai\Plugins\PluginSiteAi;
use App\Domain\Fediverse\Sockets\PluginFollowers;
use App\Domain\Fediverse\Sockets\PluginSender;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\Jobs\PluginJobs;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Plugins\SettingHosts;
use App\Domain\Site\CoreSiteInfo;
use App\Domain\Site\Insertions\CoreInsertionsChanged;
use App\Domain\Site\Insertions\Scripts\PluginInlineScripts;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Fediverse\FediverseFollowers;
use Teme\Contracts\Fediverse\FediverseSender;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Insertions\InlineScripts;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\PluginSettingsWriter;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Site\SiteInfo;

/**
 * What Teme offers a plugin's constructor: the settings reader, its own
 * secrets, the site's public facts, the content and media write doors when granted, the outbound
 * door to its hosts and those agreed in its settings when it was granted
 * http, InsertionsChanged and the InlineScripts door when it was granted
 * content.insert or pages.insert (I-064), SiteAi when it was granted ai and
 * declares AI tasks, and the other doors its capabilities open
 * (I-3353), and the writer of its own settings when it declares any.
 * Bound as the loader's PluginServices.
 */
final readonly class PluginDoors implements PluginServices
{
    public function __construct(
        private SettingsReader $settings,
        private SecretReader $secrets,
        private HostResolver $resolver,
        private StartJob $start,
        private PluginRegistry $registry,
        private CoreInsertionsChanged $insertionsChanged,
        private SettingHosts $settingHosts,
        private CoreSiteInfo $site,
    ) {}

    /**
     * Returns each service offered to this plugin, by the contract it is
     * asked for by.
     *
     * @param  list<string>  $capabilities  what the plugin was granted
     * @param  list<string>  $hosts  the hosts the outbound door may reach
     * @param  int|null  $httpSeconds  outbound ceiling; null keeps the ordinary 10 seconds
     * @param  bool  $long  built for a long job's run; a run given $httpSeconds is one too
     *
     * @return array<class-string, object>
     */
    public function for(string $name, string $code, array $capabilities, array $hosts, ?int $httpSeconds = null, bool $long = false): array
    {
        $offered = [
            SettingsReader::class => $this->settings,
            SecretReader::class => new PluginSecrets($code, $this->secrets),
        ];

        if (in_array(Capability::ContentWrite->value, $capabilities, true)) {
            $offered[ContentWriter::class] = new PluginContentWriter($code);
        }

        if (in_array(Capability::MediaWrite->value, $capabilities, true)) {
            $offered[MediaWriter::class] = new PluginMediaWriter($hosts, $this->resolver);
        }

        if (in_array(Capability::Http->value, $capabilities, true)) {
            // The hosts agreed in its settings are read at each call, never fixed here (docs/plans/plugin-sockets-1.md, A2).
            $offered[OutboundHttp::class] = new PluginHttp($hosts, $this->resolver, mostSeconds: $httpSeconds ?? PluginHttp::MOST_SECONDS, settingHosts: fn (): array => $this->settingHosts->reachable($name));
        }

        if (in_array(Capability::Jobs->value, $capabilities, true)) {
            $offered[Jobs::class] = new PluginJobs($name, $code, $this->start, $this->registry);
        }

        if (in_array(Capability::ContentInsert->value, $capabilities, true) || in_array(Capability::PagesInsert->value, $capabilities, true)) {
            $offered[InsertionsChanged::class] = $this->insertionsChanged;
            $offered[InlineScripts::class] = new PluginInlineScripts($code);
        }

        if (in_array(Capability::FediverseRead->value, $capabilities, true)) {
            $offered[FediverseFollowers::class] = new PluginFollowers;
        }

        if (in_array(Capability::FediverseSend->value, $capabilities, true)) {
            $offered[FediverseSender::class] = new PluginSender($name);
        }

        $offered[SiteInfo::class] = $this->site;

        // Its own settings group only, for the person its work runs for (docs/proposals/plugin-sockets-1.md).
        $settings = $this->registry->find($name)?->manifest?->contributes()['settings'] ?? null;

        if (is_array($settings) && $settings !== []) {
            $offered[PluginSettingsWriter::class] = new PluginSettingsWrites($code);
        }

        // Only a plugin that declares a task it may ask for (I-3434).
        $manifest = in_array(Capability::Ai->value, $capabilities, true) ? $this->registry->find($name)?->manifest : null;

        if ($manifest !== null && PluginAiTasks::of($manifest) !== []) {
            $offered[SiteAi::class] = new PluginSiteAi($code, $long || $httpSeconds !== null);
        }

        return $offered;
    }
}
