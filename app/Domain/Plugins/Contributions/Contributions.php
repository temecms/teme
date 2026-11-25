<?php

/**
 * Registers what a starting plugin adds to the admin, under its Composer
 * name: its declared settings, its Tools links and its status checks, all of
 * them or, when anything is wrong, none (I-3353). Nothing is stored: a
 * plugin that doesn't start adds nothing, and its settings' values stay.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Plugins\Editor\PanelValidator;
use App\Domain\Plugins\Seams\PluginContributions;
use App\Domain\Plugins\Seo\SeoValidator;
use App\Domain\PublicRoutes\RootFileNames;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Status\StatusChecks;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Cards\HasCards;
use Teme\Contracts\Cards\PluginCard;
use Teme\Contracts\Head\HasHeadMeta;
use Teme\Contracts\Insertions\InsertionContributor;
use Teme\Contracts\Insertions\PageInsertionContributor;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\HasPluginLists;
use Teme\Contracts\Sitemaps\HasSitemaps;
use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusCheck as PluginCheck;
use Teme\Contracts\StructuredData\HasStructuredData;

final readonly class Contributions implements PluginContributions
{
    public function __construct(
        private ContributionsValidator $validator,
        private SettingsRegistry $settings,
        private ToolLinks $links,
        private StatusChecks $checks,
        private StatusFaults $faults,
        private Container $container,
    ) {}

    /**
     * Registers the plugin's contributions and returns nothing, or returns
     * why they can't be used and registers none of them.
     *
     * @param  array<array-key, mixed>  $contributes  the manifest's `contributes`
     * @param  bool  $rootFiles  whether the manifest declares root files, whose names its settings may fill
     *
     * @return list<string>
     */
    public function add(string $name, string $code, string $label, string $version, array $contributes, object $plugin, array $jobs = [], bool $rootFiles = false): array
    {
        $errors = $this->validator->errors($contributes, $version);

        if ($errors !== []) {
            return $errors;
        }

        array_push($errors, ...$this->jobHandlers($jobs, $plugin));
        array_push($errors, ...$this->columnHandlers($contributes['columns'] ?? [], $plugin));
        array_push($errors, ...$this->listHandlers($contributes['lists'] ?? [], $plugin));
        array_push($errors, ...$this->cardHandlers($contributes['cards'] ?? [], $plugin));
        array_push($errors, ...$this->actionJobs($contributes['actions'] ?? [], $jobs));
        array_push($errors, ...app(PanelValidator::class)->startErrors($contributes, $jobs, $plugin));
        array_push($errors, ...app(SeoValidator::class)->startErrors($contributes, $plugin));

        if ($errors !== []) {
            return $errors;
        }

        // Asked before anything is registered: a plugin's check that isn't a
        // StatusCheck fails here, as the plugin failing to start.
        $checks = $plugin instanceof ChecksStatus
            ? array_map(fn (PluginCheck $check): PluginStatusCheck => new PluginStatusCheck($name, $code, $label, $check, $this->faults), $plugin->statusChecks())
            : [];

        $settings = $contributes['settings'] ?? [];

        if (is_array($settings) && $settings !== []) {
            // The manifest's description isn't handed to this method, so the group is described by the plugin's label.
            $this->settings->group(DeclaredSettings::group($code, $label, $label, $version, array_values($settings), $plugin instanceof InsertionContributor, $plugin instanceof PageInsertionContributor, rootFiles: $rootFiles, feedLinks: ($contributes['feedLinks'] ?? []) !== [], searchEngines: $plugin instanceof HasHeadMeta || $plugin instanceof HasStructuredData || $plugin instanceof HasSitemaps, cards: $plugin instanceof HasCards), $name);

            // A name its settings fill is checked when they are saved (docs/guides/developers/plugins/root-files.md).
            if ($rootFiles) {
                RootFileNames::register($this->container, $name, $code);
            }
        }

        $links = $contributes['admin'] ?? [];

        foreach (is_array($links) ? $links : [] as $link) {
            $this->links->add(new ToolLink(
                owner: $name,
                code: $code,
                id: $link['id'],
                label: $link['label'],
                description: is_string($link['description'] ?? null) ? $link['description'] : __('Added by the :label plugin.', ['label' => $label]),
                icon: is_string($link['icon'] ?? null) ? $link['icon'] : 'plug',
                to: $link['to'],
                plugin: $label,
            ));
        }

        foreach ($checks as $check) {
            $this->checks->add($check, $name);
        }

        return [];
    }

    /**
     * Declared job keys must match the plugin's handlers, and the other way
     * round (I-3445).
     *
     * @param  list<mixed>  $jobs
     *
     * @return list<string>
     */
    private function jobHandlers(array $jobs, object $plugin): array
    {
        if ($jobs === [] && ! ($plugin instanceof PluginHasJobs)) {
            return [];
        }

        $declared = [];

        foreach ($jobs as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null)) {
                $declared[$entry['key']] = true;
            }
        }

        $handlers = $plugin instanceof PluginHasJobs ? array_keys($plugin->jobs()) : [];
        $errors = [];

        foreach (array_keys($declared) as $key) {
            if (! in_array($key, $handlers, true)) {
                $errors[] = "It declares job \"{$key}\" but has no handler for it.";
            }
        }

        foreach ($handlers as $key) {
            if (! isset($declared[$key])) {
                $errors[] = "It has a handler for \"{$key}\", which isn’t declared.";
            }
        }

        return $errors;
    }

    /**
     * Declared column ids must match the plugin's listColumns() keys, and the
     * other way round. Columns are not registered here; they are read from
     * the manifest when a list asks.
     *
     *
     * @return list<string>
     */
    private function columnHandlers(mixed $columns, object $plugin): array
    {
        $declared = [];

        foreach (is_array($columns) ? $columns : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $declared[$entry['id']] = true;
            }
        }

        if (! ($plugin instanceof HasListColumns)) {
            return $declared === []
                ? []
                : ['It declares columns but doesn’t implement '.HasListColumns::class.'.'];
        }

        $handlers = array_keys($plugin->listColumns());
        $errors = [];

        foreach (array_keys($declared) as $id) {
            if (! in_array($id, $handlers, true)) {
                $errors[] = "It declares column \"{$id}\" but has no handler for it.";
            }
        }

        foreach ($handlers as $id) {
            if (! isset($declared[$id])) {
                $errors[] = "It has a handler for column \"{$id}\", which isn’t declared.";
            }
        }

        return $errors;
    }

    /**
     * Declared list ids must match the plugin's pluginLists() keys, and the
     * other way round (docs/proposals/plugin-sockets-1.md). Lists are not
     * registered here; the settings page reads them from the manifest.
     *
     * @return list<string>
     */
    private function listHandlers(mixed $lists, object $plugin): array
    {
        $declared = [];

        foreach (is_array($lists) ? $lists : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $declared[$entry['id']] = true;
            }
        }

        if (! ($plugin instanceof HasPluginLists)) {
            return $declared === []
                ? []
                : ['It declares lists but doesn’t implement '.HasPluginLists::class.'.'];
        }

        $handlers = array_keys($plugin->pluginLists());
        $errors = [];

        foreach (array_keys($declared) as $id) {
            if (! in_array($id, $handlers, true)) {
                $errors[] = "It declares list \"{$id}\" but has no class for it.";
            }
        }

        foreach ($handlers as $id) {
            if (! isset($declared[$id])) {
                $errors[] = "It has a class for list \"{$id}\", which isn’t declared.";
            }
        }

        return $errors;
    }

    /**
     * Declared card ids must match the plugin's cards() keys, and the other
     * way round, each a PluginCard (docs/guides/developers/plugins/cards.md).
     * Cards are not registered here; PluginCards reads them from the
     * manifest when a region asks.
     *
     * @return list<string>
     */
    private function cardHandlers(mixed $cards, object $plugin): array
    {
        $declared = [];

        foreach (is_array($cards) ? $cards : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $declared[$entry['id']] = true;
            }
        }

        if (! ($plugin instanceof HasCards)) {
            return $declared === []
                ? []
                : ['It declares cards but doesn’t implement '.HasCards::class.'.'];
        }

        $handlers = $plugin->cards();
        $errors = [];

        foreach (array_keys($declared) as $id) {
            if (! ($handlers[$id] ?? null) instanceof PluginCard) {
                $errors[] = "It declares card \"{$id}\" but has no ".PluginCard::class.' for it.';
            }
        }

        foreach (array_keys($handlers) as $id) {
            if (! isset($declared[$id])) {
                $errors[] = "It has a class for card \"{$id}\", which isn’t declared.";
            }
        }

        return $errors;
    }

    /**
     * Each action must queue a job the plugin declares, which needs jobs
     * (docs/proposals/plugin-sockets-1.md).
     *
     * @param  list<mixed>  $jobs
     *
     * @return list<string>
     */
    private function actionJobs(mixed $actions, array $jobs): array
    {
        $declared = [];

        foreach ($jobs as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null)) {
                $declared[$entry['key']] = true;
            }
        }

        $errors = [];

        foreach (is_array($actions) ? $actions : [] as $action) {
            if (is_array($action) && is_string($action['id'] ?? null) && is_string($action['job'] ?? null) && ! isset($declared[$action['job']])) {
                $errors[] = "Its action \"{$action['id']}\" queues the job \"{$action['job']}\", which it doesn’t declare.";
            }
        }

        return $errors;
    }
}
