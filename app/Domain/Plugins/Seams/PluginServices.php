<?php

/**
 * What Teme hands a plugin's constructor, by interface: the only way a
 * plugin is given anything, never the container (I-3353): PluginDoors
 * answers.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Seams;

use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Cards\CardsChanged;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\HtmlToDocument;
use Teme\Contracts\Content\Submissions;
use Teme\Contracts\Directory\VerifiedDirectory;
use Teme\Contracts\Fediverse\FediverseFollowers;
use Teme\Contracts\Fediverse\FediverseSender;
use Teme\Contracts\Hooks\HookSender;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Insertions\InlineScripts;
use Teme\Contracts\Insertions\InsertionsChanged;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\Notices\Notices;
use Teme\Contracts\People\PeopleReader;
use Teme\Contracts\Redirects\RedirectWriter;
use Teme\Contracts\Settings\PluginSettingsWriter;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Site\SiteInfo;
use Teme\Contracts\Taxonomy\TermWriter;

interface PluginServices
{
    /** The contracts a plugin's constructor may ask for, in the first release. */
    public const array OFFERED = [SettingsReader::class, SecretReader::class, ContentWriter::class, MediaWriter::class, OutboundHttp::class, Jobs::class, InsertionsChanged::class, FediverseFollowers::class, FediverseSender::class, SiteInfo::class, PluginSettingsWriter::class, InlineScripts::class, ContentReader::class, PeopleReader::class, SiteAi::class, HookSender::class, Notices::class, HtmlToDocument::class, VerifiedDirectory::class, Submissions::class, CardsChanged::class, RedirectWriter::class, TermWriter::class];

    /**
     * What this plugin's constructor may be given, by interface.
     *
     * @param  list<string>  $capabilities
     * @param  list<string>  $hosts
     * @param  int|null  $httpSeconds  outbound ceiling for a long job's run
     * @param  int|null  $answerBytes  outbound answer ceiling for a long job's run
     * @param  bool  $long  built for a long job's run
     *
     * @return array<class-string, object>
     */
    public function for(string $name, string $code, array $capabilities, array $hosts, ?int $httpSeconds = null, ?int $answerBytes = null, bool $long = false): array;
}
