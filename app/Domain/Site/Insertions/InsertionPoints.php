<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\InsertionContributor;
use Teme\Contracts\Plugins\Capability;
use UnexpectedValueException;

/**
 * The insertions every contributor gives for one article (I-064): read
 * plugin, then site, then core, each key's first kept. Contributors are
 * the started plugins granted content.insert, those bound here, and those
 * tagged under CONTRIBUTORS.
 */
final class InsertionPoints
{
    /** The container tag an insertion contributor is registered under. */
    public const string CONTRIBUTORS = 'teme.document.insertions';

    /** @var array<string, int> */
    private const array ORDER = ['plugin' => 1, 'site' => 2, 'core' => 3];

    /** @var list<InsertionContributor> */
    private array $bound = [];

    public function __construct(
        private Container $container,
        private LoadedPlugins $loaded,
        private PluginRecords $records,
    ) {}

    /**
     * Registers this contributor for this scope when it was granted
     * content.insert; otherwise leaves it unbound so it is never asked.
     *
     * @param  list<string>  $capabilities
     */
    public function bind(InsertionContributor $contributor, array $capabilities): void
    {
        if (! self::granted($capabilities)) {
            return;
        }

        $this->bound[] = $contributor;
    }

    /**
     * This article's insertions, in the order they were read, each key once.
     *
     * @return list<Insertion>
     */
    public function for(ArticleFacts $article): array
    {
        $kept = [];

        foreach ($this->ordered() as $contributor) {
            foreach ($contributor->insertions($article) as $insertion) {
                $kept[$insertion->key] ??= $insertion;
            }
        }

        return array_values($kept);
    }

    /**
     * @return list<InsertionContributor>
     */
    private function ordered(): array
    {
        $contributors = [...$this->plugins(), ...$this->bound];

        foreach ($this->container->tagged(self::CONTRIBUTORS) as $contributor) {
            if (! $contributor instanceof InsertionContributor) {
                throw new UnexpectedValueException('Registered as a InsertionContributor, but '.get_debug_type($contributor)." isn't one.");
            }

            $contributors[] = $contributor;
        }

        usort($contributors, function (InsertionContributor $one, InsertionContributor $other): int {
            $left = self::ORDER[$one->precedence()] ?? PHP_INT_MAX;
            $right = self::ORDER[$other->precedence()] ?? PHP_INT_MAX;

            return $left <=> $right;
        });

        return $contributors;
    }

    /**
     * The plugins started once a process that contribute insertions and
     * whose enabled row grants content.insert, so a fresh scope, as
     * PageWarmer's, still asks them.
     *
     * @return list<InsertionContributor>
     */
    private function plugins(): array
    {
        $running = array_filter($this->loaded->running(), fn (object $plugin): bool => $plugin instanceof InsertionContributor);

        if ($running === []) {
            return [];
        }

        $contributors = [];

        foreach ($this->records->enabled() as $record) {
            $plugin = $running[$record->name] ?? null;

            if ($plugin instanceof InsertionContributor && self::granted($record->consent['capabilities'] ?? [])) {
                $contributors[] = $plugin;
            }
        }

        return $contributors;
    }

    /** @param  array<mixed>  $capabilities */
    private static function granted(array $capabilities): bool
    {
        return in_array(Capability::ContentInsert->value, $capabilities, true);
    }
}
