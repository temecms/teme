<?php

/**
 * The one write step for settings, called only from inside an Operation's
 * perform(), in its transaction: UpdateSettings for the generic screen, and
 * the Operations of groups that have their own screen. It stores only
 * changed values, never defaults, records every change, and tells the page
 * cache of the group's declared tags.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Cache\PublicChanges;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\PendingNotifications;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Settings\Exceptions\StaleSettingsException;
use App\Domain\Settings\Models\SettingsChange;
use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Settings\SettingsChanged as SettingsChangedHook;

final readonly class SettingsWriter
{
    /** The prefix of a plugin's free secrets, which are never named in a change (PluginSecretStore). */
    public const string FREE_SECRET_PREFIX = 'store:';

    private const int HISTORY_KEPT = 100;

    public function __construct(private SettingsRegistry $registry) {}

    /**
     * Applies the write and returns the group's version after it. A write
     * that changes nothing leaves the version as it was.
     */
    public function write(SettingsWrite $write): int
    {
        $definition = $this->registry->find($write->group)
            ?? throw new RuntimeException("No settings group [{$write->group}] is registered.");

        SettingsGroupRecord::query()->insertOrIgnore(['group_key' => $write->group, 'overrides' => '{}', 'version' => 0]);

        $record = SettingsGroupRecord::query()->lockForUpdate()->findOrFail($write->group);

        if ($write->expectedVersion !== null && $record->version !== $write->expectedVersion) {
            throw StaleSettingsException::for($write->group, $write->expectedVersion, $record->version);
        }

        $before = $record->overrides;
        $overrides = $record->overrides;
        $secrets = $record->secrets ?? [];
        $changed = [];

        foreach ($write->values as $key => $raw) {
            $setting = $definition->setting($key)
                ?? throw new RuntimeException("No setting [{$key}] in group [{$write->group}].");
            $value = SettingRules::normalise($setting, $raw);
            $current = array_key_exists($key, $overrides) ? $overrides[$key] : $setting->default;

            if ($value === $current) {
                continue;
            }

            // Defaults are never written: a value equal to the default
            // removes the override instead (docs/architecture/settings.md).
            if ($value === $setting->default) {
                unset($overrides[$key]);
            } else {
                $overrides[$key] = $value;
            }

            $changed[] = $key;
        }

        foreach ($write->reset as $key) {
            if (array_key_exists($key, $overrides)) {
                unset($overrides[$key]);
                $changed[] = $key;
            }
        }

        // A plugin's free secret (`store:`) is saved but never named: its key would leak to every plugin and into the history.
        $hidden = false;

        foreach ($write->secrets as $key => $value) {
            if ($value === '') {
                continue;
            }

            $free = str_starts_with((string) $key, self::FREE_SECRET_PREFIX);

            if ($value === null) {
                if (array_key_exists($key, $secrets)) {
                    unset($secrets[$key]);

                    if ($free) {
                        $hidden = true;
                    } else {
                        $changed[] = $key;
                    }
                }

                continue;
            }

            $secrets[$key] = $value;

            if ($free) {
                $hidden = true;
            } else {
                $changed[] = $key;
            }
        }

        if ($changed === [] && $hidden) {
            $record->secrets = $secrets === [] ? null : $secrets;
            $record->version = $record->version + 1;
            $record->updated_by = $write->actorId === null ? null : (int) $write->actorId;
            $record->save();

            return $record->version;
        }

        if ($changed === []) {
            // The row above may be one this call just created; a save that
            // changes nothing must not leave an empty group behind. A row
            // holding a Settings › Design draft isn't empty, and stays.
            if ($record->version === 0 && $record->draft_values === null) {
                $record->delete();
            }

            return $record->version;
        }

        $changed = array_values(array_unique($changed));

        $record->overrides = $overrides;
        $record->secrets = $secrets === [] ? null : $secrets;
        $record->version = $record->version + 1;
        $record->updated_by = $write->actorId === null ? null : (int) $write->actorId;
        $record->save();

        // Secret keys are recorded as changed but never valued: before and
        // after hold overrides only.
        SettingsChange::query()->create([
            'group_key' => $write->group,
            'changed_keys' => $changed,
            'before' => array_intersect_key($before, array_flip($changed)),
            'after' => array_intersect_key($overrides, array_flip($changed)),
            'actor_id' => $record->updated_by,
            'origin' => $write->origin,
        ]);

        $this->pruneHistory($write->group);

        if ($definition->sensitive) {
            app(SecurityLog::class)->record(SecurityEventType::SecuritySettingsChanged, $record->updated_by, context: [
                'keys' => $changed,
                'by' => $record->updated_by,
            ]);
        }

        // After commit, never inside the transaction: a rollback must not
        // leave the cache purged for a change that never happened (rule 6).
        $group = $write->group;
        DB::afterCommit(fn () => Cache::memo()->forget(SettingsRepository::cacheKey($group)));

        // Every page that read the group carries its declared tags (I-263):
        // the Operation writing it purges them after commit, once the cache
        // above is forgotten.
        if ($definition->cacheTags !== []) {
            app(PublicChanges::class)->add(new CacheKeysFor(CacheKeysFor::SETTINGS, $group, $definition->cacheTags));
        }

        // Registered after the forget, and callbacks run in order: a
        // listener that runs at once reads the new values.
        $actorId = $write->actorId;
        DB::afterCommit(fn () => event(new SettingsChanged($group, $changed, $actorId)));

        // Announced to plugins only inside an Operation that someone listens
        // to: two tests write with no Operation at all.
        if (app(HookDispatcher::class)->wants(SettingsChangedHook::class)) {
            app(PendingNotifications::class)->add(SettingsChangedHook::class, fn (): SettingsChangedHook => new SettingsChangedHook($group, $changed, $actorId));
        }

        return $record->version;
    }

    /**
     * Writes one other language's words for a group (I-3360) and returns
     * the group's version after it. A value that is empty, or the same as
     * the default language's, removes that language's word, so the
     * default's shows again; so does a key in $reset. Each change is logged
     * with its language in its key, "fr.tagline". Plugins hear nothing: a
     * French title told as a plain change would say the site's name changed.
     *
     * @param  array<string, mixed>  $values  translatable key => that language's value
     * @param  list<string>  $reset  translatable keys to return to the default language's value
     *
     * @since 1.0.0
     */
    public function writeTranslations(string $group, string $language, array $values, array $reset, ?int $expectedVersion, ?string $actorId, string $origin = 'human'): int
    {
        $definition = $this->registry->find($group)
            ?? throw new RuntimeException("No settings group [{$group}] is registered.");

        SettingsGroupRecord::query()->insertOrIgnore(['group_key' => $group, 'overrides' => '{}', 'version' => 0]);

        $record = SettingsGroupRecord::query()->lockForUpdate()->findOrFail($group);

        if ($expectedVersion !== null && $record->version !== $expectedVersion) {
            throw StaleSettingsException::for($group, $expectedVersion, $record->version);
        }

        $all = $record->translations ?? [];
        $before = $all[$language] ?? [];
        $words = $before;
        $changed = [];

        foreach ($values as $key => $raw) {
            $setting = $definition->setting($key);

            if ($setting === null || ! TranslatableSettings::has($group, $key)) {
                throw new RuntimeException("Setting [{$group}.{$key}] has no words per language.");
            }

            $value = SettingRules::normalise($setting, $raw);
            $default = array_key_exists($key, $record->overrides) ? $record->overrides[$key] : $setting->default;

            if ($value === '' || $value === null || $value === $default) {
                unset($words[$key]);
            } else {
                $words[$key] = $value;
            }
        }

        foreach ($reset as $key) {
            unset($words[$key]);
        }

        foreach (array_unique([...array_keys($before), ...array_keys($words)]) as $key) {
            if (($before[$key] ?? null) !== ($words[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        if ($changed === []) {
            if ($record->version === 0 && $record->draft_values === null && $record->overrides === [] && $record->translations === null) {
                $record->delete();
            }

            return $record->version;
        }

        if ($words === []) {
            unset($all[$language]);
        } else {
            $all[$language] = $words;
        }

        $record->translations = $all;
        $record->version = $record->version + 1;
        $record->updated_by = $actorId === null ? null : (int) $actorId;
        $record->save();

        $prefix = fn (array $set): array => array_combine(
            array_map(fn (string $key): string => "{$language}.{$key}", array_keys($set)),
            array_values($set),
        );

        SettingsChange::query()->create([
            'group_key' => $group,
            'changed_keys' => array_map(fn (string $key): string => "{$language}.{$key}", $changed),
            'before' => $prefix(array_intersect_key($before, array_flip($changed))),
            'after' => $prefix(array_intersect_key($words, array_flip($changed))),
            'actor_id' => $record->updated_by,
            'origin' => $origin,
        ]);

        $this->pruneHistory($group);

        // After commit, as write() does (rule 6).
        DB::afterCommit(fn () => Cache::memo()->forget(SettingsRepository::cacheKey($group, translations: true)));

        if ($definition->cacheTags !== []) {
            app(PublicChanges::class)->add(new CacheKeysFor(CacheKeysFor::SETTINGS, $group, $definition->cacheTags));
        }

        DB::afterCommit(fn () => event(new SettingsChanged($group, $changed, $actorId, $language)));

        return $record->version;
    }

    private function pruneHistory(string $group): void
    {
        $kept = SettingsChange::query()
            ->where('group_key', $group)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_KEPT)
            ->pluck('id');

        SettingsChange::query()->where('group_key', $group)->whereNotIn('id', $kept)->delete();
    }
}
