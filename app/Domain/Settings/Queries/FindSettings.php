<?php

/**
 * Find a setting: the Settings pages and the settings a person may change
 * whose words hold every word typed, for the Settings screens' search and
 * the command palette (I-440). It reads the registry, never the database
 * and never a value.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Queries;

use App\Domain\Settings\SettingsPlacement;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class FindSettings
{
    public function __construct(private SettingsRegistry $registry, private SettingsNavigation $navigation) {}

    /**
     * Every Settings page whose name or description holds each word of
     * $query, and up to $limit settings whose label or description does,
     * both in the Settings menu's order. A setting is found on the page it
     * is changed on, with an address that lands on its field, and only when
     * the person may open that page and manage the setting's group.
     *
     * @return array{
     *     screens: list<array{key: string, label: string, href: string}>,
     *     settings: list<array{key: string, label: string, screen: string, href: string}>
     * }
     */
    public function run(string $query, User $actor, int $limit = 20): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return ['screens' => [], 'settings' => []];
        }

        $entries = $this->navigation->forUser($actor->getKey());
        $byKey = array_column($entries, null, 'key');
        $places = array_flip(array_column($entries, 'key'));
        $screens = [];

        foreach ($entries as $entry) {
            $line = SettingsPlacement::RENAMED[$entry['key']]['description'] ?? $this->registry->find($entry['key'])->description ?? '';

            if ($this->holds($entry['label'].' '.$line, $words)) {
                $screens[] = ['key' => $entry['key'], 'label' => $entry['label'], 'href' => $entry['href']];
            }
        }

        $found = [];

        foreach ($this->registry->all() as $group) {
            // A group is found on the page that draws it: its own, the page
            // that renames it (Writing, People), or the screen it is changed
            // on, as the comments switch is on Discussion's.
            $home = $group->screen === null ? SettingsPlacement::pageShowing($group->key) : Str::after($group->screen, 'settings.');

            if (! $actor->can($group->ability)) {
                continue;
            }

            foreach ($group->settings as $setting) {
                // A setting drawn on another group's page is found there, as
                // the home page is on Reading.
                $entry = $byKey[SettingsPlacement::PLACED[$group->key.'.'.$setting->key] ?? $home] ?? null;

                if ($entry === null || ! $this->holds($setting->label.' '.$setting->description, $words)) {
                    continue;
                }

                $found[] = [$places[$entry['key']], [
                    'key' => $group->key.'.'.$setting->key,
                    'label' => $setting->label,
                    'screen' => $entry['label'],
                    'href' => $entry['href'].'#field-'.$setting->key,
                ]];
            }
        }

        // Stable: within a page, settings keep the order they're declared in.
        usort($found, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return [
            'screens' => $screens,
            'settings' => array_slice(array_map(fn (array $item): array => $item[1], $found), 0, $limit),
        ];
    }

    /**
     * Whether $text holds every one of $words, ignoring case.
     *
     * @param  list<string>  $words  already lower-case
     */
    private function holds(string $text, array $words): bool
    {
        $text = mb_strtolower($text);

        foreach ($words as $word) {
            if (! str_contains($text, $word)) {
                return false;
            }
        }

        return true;
    }
}
