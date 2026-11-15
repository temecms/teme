<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Plugins\Seams\PluginContributions;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Status\StatusChecks;
use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusCheck as PluginCheck;

/**
 * Registers what a starting plugin adds to the admin, under its Composer
 * name: its declared settings, its Tools links and its status checks, all of
 * them or, when anything is wrong, none (I-3353). Nothing is stored: a
 * plugin that doesn't start adds nothing, and its settings' values stay.
 */
final readonly class Contributions implements PluginContributions
{
    public function __construct(
        private ContributionsValidator $validator,
        private SettingsRegistry $settings,
        private ToolLinks $links,
        private StatusChecks $checks,
        private StatusFaults $faults,
    ) {}

    /**
     * Registers the plugin's contributions and returns nothing, or returns
     * why they can't be used and registers none of them.
     *
     * @param  array<array-key, mixed>  $contributes  the manifest's `contributes`
     *
     * @return list<string>
     */
    public function add(string $name, string $code, string $label, string $version, array $contributes, object $plugin): array
    {
        $errors = $this->validator->errors($contributes, $version);

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
            $this->settings->group(DeclaredSettings::group($code, $label, $label, $version, array_values($settings)), $name);
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
}
