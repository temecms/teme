<?php

/**
 * Origins and approved script hashes collected while an article's body was
 * drawn with insertions, applied to the page's policy after it has been
 * started for the response (I-064). PathResolver builds the article before
 * PublicPage starts the policy, so they cannot be written there. Also says
 * which hosts a plugin declared for the content it inserts, as agreed to.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Plugins\Consent;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Site\Policy\PagePolicy;
use Teme\Contracts\Plugins\Capability;

final class InsertionOrigins
{
    /** @var array<string, array<string, true>> */
    private array $origins = [];

    /** @var array<string, true> */
    private array $scripts = [];

    public function __construct(
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
    ) {}

    /**
     * @param  array<string, list<string>>  $origins
     */
    public function add(array $origins): void
    {
        foreach ($origins as $directive => $hosts) {
            foreach ($hosts as $host) {
                $this->origins[$directive][$host] = true;
            }
        }
    }

    /**
     * Notes the hashes of approved inline scripts the article kept.
     *
     * @param  list<string>  $hashes  as PagePolicy::hash() gives them
     */
    public function addScriptHashes(array $hashes): void
    {
        foreach ($hashes as $hash) {
            $this->scripts[$hash] = true;
        }
    }

    /** Writes the collected origins and hashes into the page's policy. */
    public function apply(PagePolicy $policy): void
    {
        foreach ($this->origins as $directive => $hosts) {
            $policy->allowOrigins($directive, array_keys($hosts));
        }

        $policy->allowScriptHashes(array_keys($this->scripts));
    }

    /**
     * The hosts the content an enabled plugin inserts may load from, by
     * directive, as https origins: those its manifest's `csp` declares now
     * that were agreed to when it was enabled, the narrower of the two; none
     * for a plugin that doesn't insert.
     *
     * @return array<string, list<string>>
     */
    public function declared(string $code): array
    {
        foreach ($this->records->enabled() as $record) {
            if ($record->code !== $code) {
                continue;
            }

            $capabilities = $record->consent['capabilities'] ?? [];

            if (! in_array(Capability::ContentInsert->value, $capabilities, true) && ! in_array(Capability::PagesInsert->value, $capabilities, true) && ! in_array(Capability::SiteScripts->value, $capabilities, true)) {
                return [];
            }

            $manifest = $this->registry->all()[$record->name]->manifest ?? null;

            if ($manifest === null) {
                return [];
            }

            $origins = [];

            foreach (Consent::insertionHosts($record->consent, $manifest) as $kind => $hosts) {
                if (isset(PagePolicy::KINDS[$kind]) && $hosts !== []) {
                    $origins[PagePolicy::KINDS[$kind]] = array_map(fn (string $host): string => 'https://'.$host, $hosts);
                }
            }

            return $origins;
        }

        return [];
    }
}
