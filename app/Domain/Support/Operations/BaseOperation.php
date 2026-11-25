<?php

/**
 * Enforces the fixed order every mutation follows:
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Support\Operations;

use App\Domain\Cache\PublicChanges;
use App\Domain\Feeds\ChangedFeeds;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\PendingNotifications;
use App\Domain\Import\ImportMode;
use App\Domain\Support\Security\Elevation;
use Closure;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;
use Teme\Contracts\Feeds\FeedsChanged;
use Teme\Contracts\Hooks\Hook;
use Teme\Contracts\Hooks\Notification;
use Teme\Contracts\Operations\Operation;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;
use Throwable;

/**
 *   authorize -> elevation -> validate -> mutate (in a transaction)
 *              -> its own after-commit work -> invalidate cache
 *              -> purge pages -> notify plugins -> return
 *
 * The after-commit work, cache invalidation, the page cache's purge and
 * plugins' notifications deliberately run after commit. A rollback must not
 * leave the cache purged, or a plugin told, of a change that never happened.
 * When one Operation calls another — always through execute(), never
 * perform() — the inner one's transaction is only a savepoint, so its
 * after-commit work, invalidation, purge and notifications wait for the
 * outermost commit, and do not run at all after a rollback.
 */
abstract class BaseOperation implements Operation
{
    final public function execute(OperationInput $input): mixed
    {
        $this->authorize($input);

        // After authorize, so a refusal never reveals that elevation exists;
        // before validate, so nothing is read on behalf of an unproven actor.
        if ($this instanceof RequiresElevation && $this->requiresElevation($input)) {
            app(Elevation::class)->ensure($input);
        }

        $this->validate($input);

        // What perform() tells the page cache and announces to plugins, kept
        // apart from any Operation this one runs inside, or runs itself.
        $changes = app(PublicChanges::class);
        $notifications = app(PendingNotifications::class);
        $changes->open();
        $notifications->open();

        try {
            $result = DB::transaction(fn (): mixed => $this->perform($input));
        } catch (Throwable $failure) {
            $changes->close();
            $notifications->close();

            throw $failure;
        }

        $tags = $changes->close();
        $pending = $notifications->close();

        // Asked now, not when the after-commit work fires: a transaction around
        // the job can delay that past the job's end.
        $held = app(ImportMode::class)->active();

        // Rule 6: after the OUTERMOST commit, and never after a rollback: core's
        // own work, the caches, the purge, then plugins' notifications, last, so
        // a listener reads what the change made and can't stop the purge. Run
        // on its own, afterCommit fires at once; inside another Operation's
        // transaction it waits for that commit. Inside an import run the purge
        // and the notifications are held (I-332).
        DB::afterCommit(function () use ($result, $tags, $changes, $held, $pending): void {
            $this->afterCommit($result);
            $this->invalidateCache($result);

            if (! $held) {
                $changes->purge($tags);
                $this->feedsChanged($tags);
                app(HookDispatcher::class)->deliver($pending);
            } else {
                // Kept for work that purges its own pages when its mode ends (#716).
                app(ImportMode::class)->hold($tags);
            }
            // Later (I-332): an import run gathers its notifications and delivers them once when it ends.
        });

        return $result;
    }

    abstract protected function authorize(OperationInput $input): void;

    abstract protected function validate(OperationInput $input): void;

    abstract protected function perform(OperationInput $input): mixed;

    /**
     * The Operation's own work after the outermost commit, such as a job
     * queued or a file written for what it changed. Runs before the caches,
     * the purge and plugins' notifications.
     */
    protected function afterCommit(mixed $result): void
    {
        //
    }

    /**
     * Extension seam, for a cache other than the page cache, whose purges
     * perform() tells through purgeAfterCommit().
     */
    protected function invalidateCache(mixed $result): void
    {
        //
    }

    /**
     * Tells the page cache what this change does to what the public sees
     * (I-263): perform() reads, under its lock, the tags of the pages that
     * showed the thing before the change and show it after, and they are
     * purged after the outermost commit, never after a rollback (rule 6).
     * A change the public can't see, a draft's, tells it nothing.
     */
    protected function purgeAfterCommit(CacheKeysFor ...$changes): void
    {
        app(PublicChanges::class)->add(...$changes);
    }

    /**
     * Announces this change to plugins, after the outermost commit and never
     * during an import run (rule 6). $build runs then, and only when a plugin
     * listens for $hook.
     *
     * @param  class-string<Notification>  $hook
     * @param  Closure(): Notification  $build
     */
    protected function notifyAfterCommit(string $hook, Closure $build): void
    {
        app(PendingNotifications::class)->add($hook, $build);
    }

    /**
     * Tells plugins the feeds the purged pages' tags name, built only when
     * one listens (docs/plans/plugin-sockets-1.md, A4); a failure to build it
     * is core's, reported, and the other notifications still go.
     *
     * @param  list<string>  $tags
     */
    private function feedsChanged(array $tags): void
    {
        $dispatcher = app(HookDispatcher::class);

        if (! $dispatcher->wants(FeedsChanged::class)) {
            return;
        }

        try {
            $feeds = app(ChangedFeeds::class)->from($tags);
        } catch (Throwable $failure) {
            report($failure);

            return;
        }

        if ($feeds !== null) {
            $dispatcher->notify($feeds);
        }
    }

    /**
     * Whether a plugin listens for $hook, so perform() reads what only a
     * listener needs, such as an address about to go, only then.
     *
     * @param  class-string<Hook>  $hook
     */
    protected function listening(string $hook): bool
    {
        return app(HookDispatcher::class)->wants($hook);
    }
}
