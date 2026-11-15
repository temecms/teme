<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Publishing\Operations\Input\QueueActivityInput;
use App\Domain\Fediverse\Publishing\Operations\QueueActivity;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Import\ImportMode;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ContentVisibilityChanged;

/**
 * Core's listener on the content hooks (docs/plans/fediverse-publishing.md,
 * D1): each change to an item goes to QueueActivity, which the site runs as
 * its own work, whoever made the change. The hooks come after commit and
 * never during an import run; nothing is asked while the fediverse is
 * paused.
 */
final class ContentActivities
{
    /**
     * Subscribes core to the content hooks that change what followers see.
     */
    public static function subscribe(HookDispatcher $dispatcher): void
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
     * Queues what the change sends, as the site's own work.
     */
    public function queue(string $contentId, string $change): void
    {
        if (! FediverseSwitch::on() || app(ImportMode::class)->active()) {
            return;
        }

        $previous = app(Elevation::class);
        app()->instance(Elevation::class, new OperatorElevation);

        try {
            app(QueueActivity::class)->execute(new QueueActivityInput($contentId, $change));
        } finally {
            app()->instance(Elevation::class, $previous);
        }
    }
}
