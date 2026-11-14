<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Queries;

use App\Domain\Dashboard\MainType;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Getting started (I-241, from Ghost): the few things a new site needs, each
 * ticked off from the site's real state, for someone who may manage its
 * settings. It hides itself once all are done, or when the person hides it.
 * Each step is one bounded read. It reads only.
 */
final readonly class GettingStarted
{
    public function __construct(
        private SettingsReader $settings,
        private SettingsRegistry $registry,
    ) {}

    /**
     * The steps, each with whether it is done and where it is done; null when
     * the viewer may not manage the site's settings, has hidden the list, or
     * has nothing left to do.
     *
     * @return array{steps: list<array{key: string, label: string, done: bool, url: string}>}|null
     */
    public function forUser(User $viewer): ?array
    {
        if (! $viewer->can('settings.general.manage') || PreferencesFor::user($viewer)['dashboard.getting_started_hidden'] === true) {
            return null;
        }

        $main = MainType::get();
        $general = route('settings.show', ['group' => 'general']);

        // Later (1.6): "Choose a theme" joins when there is more than one theme to choose.
        $steps = [
            ['key' => 'site_title', 'label' => 'Name your site', 'done' => ! $this->isDefault('site_title'), 'url' => $general],
            ['key' => 'timezone', 'label' => 'Set your time zone', 'done' => ! $this->isDefault('timezone'), 'url' => $general],
            [
                'key' => 'invite',
                'label' => 'Invite a colleague',
                // Anyone else, an invitation included.
                'done' => DB::table('users')->where('id', '!=', $viewer->getAuthIdentifier())->exists(),
                'url' => route('users.index'),
            ],
            [
                'key' => 'publish',
                'label' => 'Publish your first '.mb_strtolower($main->singular),
                'done' => (int) DB::table('content_counts')->where('type', $main->key)->where('bucket', 'published')->where('author_id', 0)->value('count') > 0,
                // The list, where Add new opens a draft in the editor (I-425).
                'url' => route('content.index', ['type' => $main->segment]),
            ],
        ];

        if (array_filter($steps, fn (array $step): bool => ! $step['done']) === []) {
            return null;
        }

        return ['steps' => $steps];
    }

    /** Whether a General setting still holds its registered default. */
    private function isDefault(string $key): bool
    {
        $default = $this->registry->find('general')?->setting($key)?->default;

        return $this->settings->string('general', $key) === (string) $default;
    }
}
