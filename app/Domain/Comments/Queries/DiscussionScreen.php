<?php

/**
 * Settings › Discussion's data (E1, E2; 1.8d's D2, D4): the comments switch
 * from the `modules` group, then the `discussion` group's settings in the
 * approved design's five sections, with the drawn line that isn't a setting.
 * People's pictures are on Settings › People.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Comments\Queries;

use App\Domain\Settings\Modules;
use App\Domain\Settings\Queries\SettingsForm;
use App\Models\User;
use RuntimeException;

final readonly class DiscussionScreen
{
    /**
     * v9's fieldsets (SETTING_FIELDSETS), legend => the discussion group's
     * keys in the order drawn. Every setting of the group is in exactly one
     * (DiscussionSettingsScreenTest), so one a later part adds can't go
     * missing from the screen.
     *
     * @var array<string, list<string>>
     */
    public const array FIELDSETS = [
        'Where comments are open' => ['open_for', 'signed_in_only', 'ask_website', 'close_after_days'],
        'Threads and pages' => ['thread_depth', 'per_page'],
        'Moderation' => ['hold_all', 'approve_returning', 'hold_links', 'hold_list', 'block_list'],
        'Emails' => ['email_moderators', 'email_authors'],
        'Privacy' => ['keep_ip_days'],
    ];

    /**
     * Each fieldset's one line (I-3128), legend => line, in FIELDSETS' order.
     *
     * @var array<string, string>
     */
    public const array DESCRIPTIONS = [
        'Where comments are open' => '',
        'Threads and pages' => '',
        'Moderation' => 'Which comments wait for someone to approve them.',
        'Emails' => '',
        'Privacy' => '',
    ];

    /**
     * The drawn lines of words that aren't settings, first in their
     * fieldset (v9's info fields, :5689 and :5698).
     *
     * @var array<string, array{label: string, value: string, hint: string}>
     */
    public const array INFO = [
        'Moderation' => [
            'label' => 'Who skips these checks',
            'value' => 'Editors and administrators, and authors on their own posts: their comments are approved at once.',
            'hint' => 'Whoever may publish an item looks after its comments. Contributors’ comments meet these checks, as readers’ do.',
        ],
    ];

    public function __construct(private SettingsForm $form) {}

    /**
     * Settings › Discussion as its page needs it: the switch and whether this
     * person may change it, then, while comments are on, the five fieldsets
     * with the group's fields and values and the version to save against;
     * while they're off, none.
     *
     * @return array{
     *     description: string,
     *     comments: array{field: array<string, mixed>, value: string, version: int, canSwitch: bool},
     *     fieldsets: list<array{legend: string, description: string, info: array{label: string, value: string, hint: string}|null, fields: list<array<string, mixed>>}>,
     *     values: array<string, mixed>,
     *     version: int
     * }
     */
    public function data(User $viewer): array
    {
        $modules = $this->form->find('modules') ?? throw new RuntimeException('No settings group [modules] is registered.');
        $discussion = $this->form->find('discussion') ?? throw new RuntimeException('No settings group [discussion] is registered.');
        $switch = array_values(array_filter($modules['fields'], fn (array $field): bool => $field['key'] === Modules::COMMENTS))[0]
            ?? throw new RuntimeException('The modules group has no comments switch.');
        $on = Modules::on(Modules::COMMENTS);
        $fields = array_column($discussion['fields'], null, 'key');
        $fieldsets = [];

        if ($on) {
            foreach (self::FIELDSETS as $legend => $keys) {
                $fieldsets[] = [
                    'legend' => $legend,
                    'description' => self::DESCRIPTIONS[$legend],
                    'info' => self::INFO[$legend] ?? null,
                    'fields' => array_values(array_map(fn (string $key): array => $fields[$key], array_filter($keys, fn (string $key): bool => isset($fields[$key])))),
                ];
            }
        }

        return [
            'description' => $discussion['group']['description'],
            'comments' => [
                'field' => $switch,
                'value' => (string) ($modules['values'][Modules::COMMENTS] ?? Modules::ON),
                'version' => $modules['version'],
                'canSwitch' => $viewer->can('settings.modules.manage'),
            ],
            'fieldsets' => $fieldsets,
            'values' => $on ? $discussion['values'] : [],
            'version' => $discussion['version'],
        ];
    }
}
