<?php

/**
 * Finds the panels enabled plugins add to Home, read from each plugin's
 * manifest, `contributes.homePanels`, and built from blocks of its own area
 * (docs/guides/developers/plugins/home-panels.md). The Home registry asks for
 * them when it is built, so a disabled plugin's panel is gone at once, and a
 * panel is shown only to a person with the plugin area's permission. Holds
 * nothing itself.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use App\Domain\Plugins\PluginRegistry;

final class PluginHomePanels
{
    /** Where plugins' panels sort: after core's own panels, in the order they were declared. @since 1.8.0 */
    public const int FIRST_ORDER = 700;

    /** @var list<HomePanelDeclaration>|null the declarations, read once per request */
    private ?array $declarations = null;

    public function __construct(
        private readonly PluginAreas $areas,
        private readonly PluginRegistry $registry,
    ) {}

    /**
     * Every panel of every enabled plugin, in the order they were declared;
     * read from the plugins' areas once per request, then kept.
     *
     * @return list<HomePanelDeclaration>
     */
    public function declared(): array
    {
        if ($this->declarations !== null) {
            return $this->declarations;
        }

        $declared = [];

        foreach ($this->areas->all() as $plugin) {
            $manifest = $this->registry->find($plugin->name)?->manifest;
            $panels = $manifest?->contributes()['homePanels'] ?? null;

            if (! is_array($panels)) {
                continue;
            }

            foreach ($panels as $panel) {
                if (! is_array($panel) || ! is_string($panel['id'] ?? null) || ! is_string($panel['page'] ?? null) || ! is_array($panel['blocks'] ?? null)) {
                    continue;
                }

                $declared[] = new HomePanelDeclaration(
                    key: "plugin.{$plugin->code}.{$panel['id']}",
                    plugin: $plugin,
                    title: AreaWords::text($plugin->code, is_string($panel['title'] ?? null) ? $panel['title'] : $panel['id']),
                    size: is_string($panel['size'] ?? null) ? $panel['size'] : null,
                    order: self::FIRST_ORDER + count($declared),
                    page: $panel['page'],
                    blocks: array_values(array_filter($panel['blocks'], is_string(...))),
                );
            }
        }

        return $this->declarations = $declared;
    }

    /**
     * The declared panel with this key, or null when no enabled plugin declares it.
     *
     * @since 1.8.0
     */
    public function find(string $key): ?HomePanelDeclaration
    {
        foreach ($this->declared() as $declared) {
            if ($declared->key === $key) {
                return $declared;
            }
        }

        return null;
    }
}
