<?php

/**
 * Every registered settings group with its values as the site reads them:
 * each saved override, else the registry's default. Never a secret setting or
 * the discussion lists that can hold IP addresses (I-2408). It reads the
 * table itself, because the settings repository fills the cache, and nothing
 * may be cached from inside the export's snapshot.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKind;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Support\Facades\DB;
use stdClass;
use Teme\Contracts\Settings\SettingType;

final readonly class SettingsKind implements ExportKind
{
    /** Settings never exported by name: moderators can type IP addresses into them (I-2408). */
    private const array LEFT_OUT = ['discussion.hold_list', 'discussion.block_list'];

    public function __construct(private SettingsRegistry $registry) {}

    public function name(): string
    {
        return 'settings';
    }

    public function version(): int
    {
        return 1;
    }

    public function description(): string
    {
        return 'Every settings group, in the order they are registered, with its values as the site reads them. Settings that are secrets are never included, nor the discussion settings’ hold and block lists.';
    }

    public function noun(): string
    {
        return 'settings group|settings groups';
    }

    public function fields(): array
    {
        return [
            'group' => 'The group\'s key, such as `general`.',
            'values' => 'Its settings, key by key, as an object: each one\'s saved value, or its default.',
        ];
    }

    public function lines(ExportContext $context): iterable
    {
        // One statement: tens of rows. secrets and draft_values are never selected.
        $stored = [];

        foreach (DB::select('SELECT group_key, overrides FROM settings') as $row) {
            $stored[(string) $row->group_key] = (string) $row->overrides;
        }

        foreach ($this->registry->all() as $group) {
            $overrides = ExportJson::decode($stored[$group->key] ?? '{}');
            $values = new stdClass;

            foreach ($group->settings as $setting) {
                if ($setting->type === SettingType::Secret || in_array($group->key.'.'.$setting->key, self::LEFT_OUT, true)) {
                    continue;
                }

                $values->{$setting->key} = $overrides instanceof stdClass && property_exists($overrides, $setting->key)
                    ? $overrides->{$setting->key}
                    : $setting->default;
            }

            yield ['group' => $group->key, 'values' => $values];
        }
    }
}
