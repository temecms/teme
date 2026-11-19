<?php

/**
 * The site's settings that can name a person by their address: the
 * administration email, the comment hold and block lists, and the history
 * of their changes. Exported where a value equals the address, and kept on
 * erasure, each with its reason: they run the site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings\Privacy;

use App\Domain\Comments\DiscussionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;
use Teme\Contracts\Settings\SettingsReader;

final class SiteSettingsHolder implements PersonalDataHolder
{
    /** The settings that can hold an address, by group. */
    private const array WATCHED = [
        'general' => ['admin_email'],
        'discussion' => ['hold_list', 'block_list'],
    ];

    public function __construct(private readonly SettingsReader $settings) {}

    public function key(): string
    {
        return 'site-settings';
    }

    public function label(): string
    {
        return __('Site settings');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $current = $this->current($subject->email);
        $groups = $current === [] ? [] : [new ExportGroup(__('Site settings'), $current)];

        foreach ($this->history($subject->email) as $change) {
            $groups[] = new ExportGroup(__('Settings change'), [
                __('Date') => $change['date'],
                __('Setting') => implode(', ', $change['settings']),
            ]);
        }

        return new ExportPage($groups, true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $current = $this->current($subject->email);
        $history = $this->history($subject->email);
        $reasons = [];

        if (array_key_exists(__('Administration email'), $current)) {
            $reasons[] = __("The site's administration email is kept. Change it under Settings › General.");
        }

        if (array_key_exists(__('Comment hold list'), $current) || array_key_exists(__('Comment block list'), $current)) {
            $reasons[] = __('The comment hold and block lists are kept, because they are used to stop abuse.');
        }

        if ($history !== []) {
            $reasons[] = __('The history of settings changes is kept, as the record of who changed what.');
        }

        $kept = array_sum(array_map(fn (string $value): int => count(explode("\n", $value)), $current)) + count($history);

        return new ErasePage(0, $kept, $reasons, true);
    }

    /**
     * What the settings hold now that equals the address, by its label: one
     * line each, several of one setting joined on new lines.
     *
     * @return array<string, string>
     */
    private function current(string $email): array
    {
        $discussion = DiscussionSettings::current();
        $found = [
            __('Administration email') => self::equal($email, [(string) $this->settings->string('general', 'admin_email')]),
            __('Comment hold list') => self::equal($email, $discussion->holdList),
            __('Comment block list') => self::equal($email, $discussion->blockList),
        ];

        return array_map(fn (array $lines): string => implode("\n", $lines), array_filter($found, fn (array $lines): bool => $lines !== []));
    }

    /**
     * Every change to a watched setting whose old or new value equals the
     * address: found by a text search, then confirmed value by value.
     *
     * @return list<array{date: string, settings: list<string>}>
     */
    private function history(string $email): array
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email).'%';
        $rows = DB::table('settings_changes')
            ->whereIn('group_key', array_keys(self::WATCHED))
            ->where(fn ($query) => $query->whereRaw('lower(before::text) LIKE ?', [$pattern])->orWhereRaw('lower(after::text) LIKE ?', [$pattern]))
            ->orderBy('id')
            ->get(['group_key', 'before', 'after', 'created_at']);

        $changes = [];

        foreach ($rows as $row) {
            $settings = [];

            foreach ([(string) $row->before, (string) $row->after] as $json) {
                $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

                foreach (self::WATCHED[(string) $row->group_key] as $key) {
                    if (is_array($values) && array_key_exists($key, $values) && self::equal($email, self::lines($values[$key])) !== []) {
                        $settings[$key] = self::name($key);
                    }
                }
            }

            if ($settings !== []) {
                $changes[] = ['date' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(), 'settings' => array_values($settings)];
            }
        }

        return $changes;
    }

    /**
     * @param  list<string>  $lines
     *
     * @return list<string> the lines equal to the address, whatever their letter case
     */
    private static function equal(string $email, array $lines): array
    {
        return array_values(array_filter($lines, fn (string $line): bool => mb_strtolower(trim($line)) === $email));
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(fn (mixed $line): string => is_scalar($line) ? (string) $line : '', $value));
        }

        return is_string($value) ? (preg_split('/\R/', $value) ?: []) : [];
    }

    private static function name(string $key): string
    {
        return match ($key) {
            'admin_email' => __('Administration email'),
            'hold_list' => __('Comment hold list'),
            default => __('Comment block list'),
        };
    }
}
