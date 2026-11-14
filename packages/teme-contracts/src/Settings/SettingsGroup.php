<?php

declare(strict_types=1);

namespace Teme\Contracts\Settings;

/**
 * A set of settings stored, cached, permitted and edited together — one row,
 * one form, one ability.
 *
 * @since 0.1.0
 */
final readonly class SettingsGroup
{
    /**
     * @param  string  $key  e.g. 'general'; later 'theme.<code>' and 'plugin.<code>'
     * @param  string  $section  the sidebar section under Settings
     * @param  string  $ability  the permission required to change this group
     * @param  list<Setting>  $settings
     * @param  list<string>  $cacheTags  page-cache tags: every page that reads the group carries them, and a change to it purges them; `*` purges every page
     * @param  bool  $sensitive  changing it requires a fresh proof of identity (SECURITY.md commitment 12)
     * @param  string|null  $screen  the route name of the group's own screen; a group that has one is edited
     *                               only there, never through the generic settings form. @since 0.1.0
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $section,
        public string $ability,
        public array $settings,
        public string $since,
        public array $cacheTags = [],
        public bool $sensitive = false,
        public ?string $screen = null,
    ) {}

    /** @since 0.1.0 */
    public function setting(string $key): ?Setting
    {
        foreach ($this->settings as $setting) {
            if ($setting->key === $key) {
                return $setting;
            }
        }

        return null;
    }
}
