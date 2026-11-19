<?php

/**
 * The links plugins add to every feed (docs/plans/plugin-sockets-1.md, A3):
 * values their manifests declare under `contributes.feedLinks`, read for
 * the started plugins whose enabled row grants feeds.links, in the order
 * they started. Core writes the XML; a plugin runs no code here. A link
 * that isn't an absolute https address is skipped, each rel and href once,
 * and a plugin whose links can't be read is recorded and skipped.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Feeds;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Contracts\Container\Container;
use Teme\Contracts\Plugins\Capability;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final readonly class PluginFeedLinks
{
    /** The most links one urls setting gives. */
    public const int MOST_FROM_A_LIST = 5;

    public function __construct(
        private LoadedPlugins $loaded,
        private PluginRecords $records,
        private PluginRegistry $registry,
        private SettingsReader $settings,
        private Container $container,
    ) {}

    /**
     * Returns every plugin's links, each with the code of the plugin that adds it.
     *
     * @return list<array{rel: string, href: string, code: string}>
     */
    public function for(): array
    {
        $running = $this->loaded->running();

        if ($running === []) {
            return [];
        }

        $granted = [];

        foreach ($this->records->enabled() as $record) {
            if (in_array(Capability::FeedsLinks->value, $record->consent['capabilities'] ?? [], true)) {
                $granted[$record->name] = true;
            }
        }

        $links = [];

        foreach (array_keys($running) as $name) {
            if (! isset($granted[$name])) {
                continue;
            }

            try {
                foreach ($this->declared($name) as $link) {
                    $links[$link['rel']."\n".$link['href']] ??= $link;
                }
            } catch (Throwable $failure) {
                $this->container->make(ListenerFaults::class)->record($name, $name.'#feed-links', '', $failure);
            }
        }

        return array_values($links);
    }

    /**
     * The links one plugin's manifest declares, its settings read in.
     *
     * @return list<array{rel: string, href: string, code: string}>
     */
    private function declared(string $name): array
    {
        $manifest = $this->registry->find($name)?->manifest;
        $declared = $manifest?->contributes()['feedLinks'] ?? [];

        if ($manifest === null || ! is_array($declared) || $declared === []) {
            return [];
        }

        $code = $manifest->code();
        $links = [];

        foreach ($declared as $entry) {
            $rel = is_array($entry) && is_string($entry['rel'] ?? null) ? $entry['rel'] : '';
            $href = is_array($entry) && is_string($entry['href'] ?? null) ? $entry['href'] : '';

            foreach ($this->addresses($code, $href) as $address) {
                if ($rel === 'hub' && self::https($address)) {
                    $links[] = ['rel' => $rel, 'href' => $address, 'code' => $code];
                }
            }
        }

        return $links;
    }

    /**
     * A literal href, or the value of the plugin's own setting `{key}` names:
     * a urls setting's first five addresses.
     *
     * @return list<string>
     */
    private function addresses(string $code, string $href): array
    {
        if (preg_match('/^\{([a-z][a-z0-9_]{1,40})\}$/D', $href, $match) !== 1) {
            return [$href];
        }

        $value = $this->settings->group('plugin.'.$code)[$match[1]] ?? null;

        if (is_array($value)) {
            return array_slice(array_values(array_filter($value, is_string(...))), 0, self::MOST_FROM_A_LIST);
        }

        return is_string($value) ? [$value] : [];
    }

    private static function https(string $address): bool
    {
        return strlen($address) <= 2048
            && filter_var($address, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($address, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($address, PHP_URL_HOST) !== '';
    }
}
