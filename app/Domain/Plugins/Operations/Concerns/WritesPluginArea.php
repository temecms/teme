<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Concerns;

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Area\AreaPlugin;
use App\Domain\Plugins\Area\AreaTooSlow;
use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Users\Actors;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Content\WriteRefused;
use Throwable;

/**
 * What the writes in a plugin's area share (docs/plans/plugin-sockets-2.md):
 * who may write, a call into the plugin under its 2-second budget that
 * rolls everything back on a refusal or a fault, and the purge of the
 * area's declared tags after commit.
 */
trait WritesPluginArea
{
    /**
     * The plugin's area, when this person holds its permission, the plugin
     * is enabled and running with an area, and the record is declared.
     *
     * @throws AuthorizationException otherwise
     */
    private function writable(string $code, string $record, string $actorId): AreaPlugin
    {
        $person = app(Actors::class)->find($actorId);
        $plugin = app(PluginAreas::class)->find($code);

        if ($person === null || $plugin === null || $plugin->data === null || $plugin->area->record($record) === null || ! $person->can($plugin->ability())) {
            throw new AuthorizationException(__('Not permitted.'));
        }

        return $plugin;
    }

    /**
     * Calls the plugin's write inside the Operation's transaction. A refusal
     * becomes its sentence at the top of the form; any other failure, or a
     * call over budget, is recorded and says nothing was changed. Either way
     * the transaction rolls back, the plugin's own rows with it.
     *
     * @param  Closure(PluginArea): Saved  $write
     */
    private function write(AreaPlugin $plugin, Closure $write): Saved
    {
        $clock = app(ColumnClock::class);
        $started = $clock->nanoseconds();

        try {
            $saved = $write($plugin->data ?? throw new AuthorizationException(__('Not permitted.')));
        } catch (WriteRefused $refused) {
            throw ValidationException::withMessages(['record' => $refused->getMessage() !== '' ? $refused->getMessage() : $this->unsaved($plugin)]);
        } catch (ValidationException|AuthorizationException $refused) {
            throw $refused;
        } catch (Throwable $failure) {
            $this->areaFault($plugin, $failure);

            throw ValidationException::withMessages(['record' => $this->unsaved($plugin)]);
        }

        $took = $clock->nanoseconds() - $started;

        if ($took > self::WRITE_BUDGET_MS * 1_000_000) {
            $this->areaFault($plugin, new AreaTooSlow('write', intdiv($took, 1_000_000)));

            throw ValidationException::withMessages(['record' => $this->unsaved($plugin)]);
        }

        if ($saved->changed && $plugin->area->purges !== []) {
            $this->purgeAfterCommit(new CacheKeysFor(CacheKeysFor::SITE, 'plugin:'.$plugin->code, $plugin->area->purges));
        }

        return $saved;
    }

    private function unsaved(AreaPlugin $plugin): string
    {
        return __(':plugin couldn’t save this. Nothing was changed.', ['plugin' => $plugin->label]);
    }

    private function areaFault(AreaPlugin $plugin, Throwable $failure): void
    {
        try {
            app(ListenerFaults::class)->record($plugin->name, "{$plugin->name}#area", PluginArea::class, $failure);
        } catch (Throwable) {
            // A fault that can't be recorded never hides the refusal.
        }
    }
}
