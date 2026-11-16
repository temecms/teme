<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Publishing\Operations\Input\QueueActivityInput;
use App\Domain\Fediverse\Publishing\Operations\QueueActivity;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Import\ImportMode;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ContentVisibilityChanged;
use Throwable;
use WeakMap;

/**
 * Core's listener on the content hooks (docs/plans/fediverse-publishing.md,
 * D1): each change to an item goes to QueueActivity as the site's own work
 * (a SiteWork token, never the operator), whoever made the change. The hooks come after commit and
 * never during an import run. Core listens only once the fediverse is on,
 * checked as each request, command or queued job starts, so a site that
 * never turns it on builds no hook for it; nothing is asked while paused.
 */
final class ContentActivities
{
    /** Where the container keeps the dispatchers core listens on. */
    private const string LISTENING = 'fediverse.content-listening';

    /**
     * Subscribes core to the content hooks once the fediverse is on, once
     * for each dispatcher.
     */
    public static function listen(HookDispatcher $dispatcher): void
    {
        $listening = app()->bound(self::LISTENING) ? app(self::LISTENING) : null;

        if (! $listening instanceof WeakMap) {
            $listening = new WeakMap;
            app()->instance(self::LISTENING, $listening);
        }

        if (isset($listening[$dispatcher]) || ! self::on()) {
            return;
        }

        $listening[$dispatcher] = true;
        self::subscribe($dispatcher);
    }

    /**
     * Subscribes core to the content hooks that change what followers see.
     */
    private static function subscribe(HookDispatcher $dispatcher): void
    {
        $core = $dispatcher->subscriber(HookDispatcher::CORE);
        $queue = fn (string $contentId, string $change) => app(self::class)->queue($contentId, $change);

        $core->on(ContentPublished::class, fn (ContentPublished $hook) => $queue($hook->item->id, QueueActivityInput::PUBLISHED));
        $core->on(ContentUnpublished::class, fn (ContentUnpublished $hook) => $queue($hook->item->id, QueueActivityInput::REMOVED));
        $core->on(ContentTrashed::class, fn (ContentTrashed $hook) => $queue($hook->item->id, QueueActivityInput::REMOVED));
        $core->on(ContentDeleted::class, fn (ContentDeleted $hook) => $queue($hook->id, QueueActivityInput::REMOVED));
        $core->on(ContentVisibilityChanged::class, fn (ContentVisibilityChanged $hook) => $queue(
            $hook->item->id,
            $hook->to === 'public' ? QueueActivityInput::PUBLISHED : QueueActivityInput::REMOVED,
        ));
    }

    /**
     * Whether the fediverse is on, or off when its setting can't be read yet.
     */
    private static function on(): bool
    {
        try {
            return FediverseSwitch::on();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Queues what the change sends, as the site's own work.
     */
    public function queue(string $contentId, string $change): void
    {
        if (! FediverseSwitch::on() || app(ImportMode::class)->active()) {
            return;
        }

        app(QueueActivity::class)->execute(new QueueActivityInput($contentId, $change, siteWork: SiteWork::issue()));
    }
}
