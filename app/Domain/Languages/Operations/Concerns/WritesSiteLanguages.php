<?php

/**
 * What the three Operations that change a site's languages share (D10): who
 * may, the prefix's rules, and the `languages` group read under its row's
 * lock and written back with the version the form was opened on, so a
 * stale form is refused as every settings form's is.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages\Operations\Concerns;

use App\Domain\Languages\PrefixConflicts;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

trait WritesSiteLanguages
{
    /** A prefix: lower-case letters and digits, words joined by one hyphen. */
    private const string PREFIX = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** The longest prefix. */
    private const int LONGEST = 20;

    /**
     * Only someone who may manage the General settings changes the languages (D23).
     *
     * @throws AuthorizationException
     */
    private function mayManage(?string $actorId): void
    {
        $actor = $actorId === null ? null : User::query()->find($actorId);

        if ($actor === null || ! $actor->can('settings.general.manage')) {
            throw new AuthorizationException('Not permitted to change the site\'s languages.');
        }
    }

    /**
     * Refuses a prefix of the wrong shape, or one something else holds;
     * $tag is the language whose own prefix and addresses it may keep.
     *
     * @throws ValidationException
     */
    private function checkPrefix(string $prefix, ?string $tag): void
    {
        if (mb_strlen($prefix) > self::LONGEST || preg_match(self::PREFIX, $prefix) !== 1) {
            throw ValidationException::withMessages(['prefix' => __('Use lower-case letters, digits and hyphens, at most 20.')]);
        }

        $conflict = PrefixConflicts::for($prefix, $tag);

        if ($conflict !== null) {
            throw ValidationException::withMessages(['prefix' => $conflict]);
        }
    }

    /**
     * The group's stored lists, read under its row's lock: the other
     * languages, each one's prefix, and the hidden ones.
     *
     * @return array{others: list<string>, prefixes: array<string, string>, hidden: list<string>}
     */
    private function current(): array
    {
        SettingsGroupRecord::query()->insertOrIgnore(['group_key' => SiteLanguages::GROUP, 'overrides' => '{}', 'version' => 0]);
        $overrides = SettingsGroupRecord::query()->whereKey(SiteLanguages::GROUP)->lockForUpdate()->firstOrFail()->overrides;
        $prefixes = [];

        foreach ((array) ($overrides['prefixes'] ?? []) as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['value'] ?? null)) {
                $prefixes[$row['name']] = $row['value'];
            }
        }

        $others = array_values(array_filter((array) ($overrides['others'] ?? []), is_string(...)));

        return [
            // In the prefixes' order, the switcher's (docs/proposals/multilingual-screens.md, 2): the list itself keeps the config's.
            'others' => array_values(array_unique([...array_intersect(array_keys($prefixes), $others), ...$others])),
            'prefixes' => $prefixes,
            'hidden' => array_values(array_filter((array) ($overrides['hidden'] ?? []), is_string(...))),
        ];
    }

    /**
     * Writes the lists back, refused as stale when the group moved on since $expectedVersion.
     *
     * @param  array{others: list<string>, prefixes: array<string, string>, hidden: list<string>}  $lists
     */
    private function store(array $lists, int $expectedVersion, ?string $actorId): void
    {
        $rows = [];

        foreach ($lists['others'] as $tag) {
            $rows[] = ['name' => $tag, 'value' => $lists['prefixes'][$tag] ?? mb_strtolower($tag)];
        }

        app(SettingsWriter::class)->write(new SettingsWrite(
            group: SiteLanguages::GROUP,
            values: ['others' => $lists['others'], 'prefixes' => $rows, 'hidden' => array_values(array_intersect($lists['hidden'], $lists['others']))],
            expectedVersion: $expectedVersion,
            actorId: $actorId,
            origin: 'human',
        ));
    }

    /**
     * A language's English name, its tag when config/languages.php doesn't list it.
     */
    private static function named(string $tag): string
    {
        return app(SiteLanguages::class)->label($tag);
    }
}
