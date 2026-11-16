<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Contents\Queries\ItemSnapshots;
use App\Domain\Contents\Queries\PublishedItems;
use App\Domain\Contents\Queries\QueuedItems;
use App\Domain\Fediverse\Sockets\PluginFollowers;
use App\Domain\Fediverse\Sockets\PluginSender;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\Jobs\PluginJobs;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Site\Insertions\CoreInsertionsChanged;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Fediverse\FediverseFollowers;
use Teme\Contracts\Fediverse\FediverseSender;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What Teme offers a plugin's constructor: the settings reader, its own
 * secrets, the content and media write doors when granted, the outbound
 * door to its hosts when it was granted http, InsertionsChanged when it was
 * granted content.insert (I-064), the content reader when it was granted
 * content.read, and the other doors its capabilities open
 * (I-3353). Bound as the loader's
 * PluginServices.
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
        private PublishedItems $published,
        private ItemSnapshots $snapshots,
        private QueuedItems $queued,
    ) {}

    /**
     * Returns each service offered to this plugin, by the contract it is
     * asked for by.
     *
     * @param  list<string>  $capabilities  what the plugin was granted
     * @param  list<string>  $hosts  the hosts the outbound door may reach
     * @param  int|null  $httpSeconds  outbound ceiling; null keeps the ordinary 10 seconds
     * @param  int|null  $answerBytes  outbound answer ceiling; null keeps the ordinary 1 MB
     *
     * @return array<class-string, object>
     */
    public function for(string $name, string $code, array $capabilities, array $hosts, ?int $httpSeconds = null, ?int $answerBytes = null): array
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
            $offered[OutboundHttp::class] = new PluginHttp($hosts, $this->resolver, maxBytes: $answerBytes ?? PluginHttp::MOST_BYTES, mostSeconds: $httpSeconds ?? PluginHttp::MOST_SECONDS);
        }

        if (in_array(Capability::Jobs->value, $capabilities, true)) {
            $offered[Jobs::class] = new PluginJobs($name, $code, $this->start, $this->registry);
        }

        if (in_array(Capability::ContentInsert->value, $capabilities, true)) {
            $offered[InsertionsChanged::class] = $this->insertionsChanged;
        }

        if (in_array(Capability::FediverseRead->value, $capabilities, true)) {
            $offered[FediverseFollowers::class] = new PluginFollowers;
        }

        if (in_array(Capability::FediverseSend->value, $capabilities, true)) {
            $offered[FediverseSender::class] = new PluginSender($name);
        }

        if (in_array(Capability::ContentRead->value, $capabilities, true)) {
            $offered[ContentReader::class] = new PluginContentReader($this->published, $this->snapshots, $this->queued);
        }

        return $offered;
    }
}
