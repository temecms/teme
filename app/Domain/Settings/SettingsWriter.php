<?php

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

/**
 * The one write step for settings, called only from inside an Operation's
 * perform(), in its transaction: UpdateSettings for the generic screen, and
 * the Operations of groups that have their own screen. It stores only
 * changed values, never defaults, records every change, and tells the page
 * cache of the group's declared tags.
 */
final readonly class SettingsWriter
{
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

        foreach ($write->secrets as $key => $value) {
            if ($value === '') {
                continue;
            }

            if ($value === null) {
                if (array_key_exists($key, $secrets)) {
                    unset($secrets[$key]);
                    $changed[] = $key;
                }

                continue;
            }

            $secrets[$key] = $value;
            $changed[] = $key;
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
