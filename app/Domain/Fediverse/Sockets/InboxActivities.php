<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\Replies\InboxReplies;
use App\Domain\Fediverse\Signatures\VerifiedSender;
use App\Domain\Hooks\HookDispatcher;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Fediverse\ActivityHandler;
use Teme\Contracts\Fediverse\ActivityReceived;
use Teme\Contracts\Fediverse\CollectActivityHandlers;
use Throwable;

/**
 * What the inbox's queued job does with an activity beyond following
 * (docs/plans/fediverse-publishing.md, D5): plugins granted fediverse.read
 * hear of every activity taken; a reply goes to the comments; any other
 * type Teme does not take itself goes to the plugins' handlers for it.
 */
final readonly class InboxActivities
{
    public function __construct(
        private HookDispatcher $hooks,
        private InboxReplies $replies,
    ) {}

    /**
     * Tells the plugins of an activity the inbox took.
     *
     * @param  array<string, mixed>  $activity
     */
    public function received(array $activity, VerifiedSender $sender): void
    {
        $type = $activity['type'] ?? null;

        if (is_string($type) && $this->hooks->wants(ActivityReceived::class)) {
            $this->hooks->notify(new ActivityReceived($activity, $type, $sender->actorUri));
        }
    }

    /**
     * Takes an activity core has no step of its own for: a reply, or one a
     * plugin handles.
     *
     * @param  array<string, mixed>  $activity
     */
    public function other(array $activity, VerifiedSender $sender): void
    {
        $type = $activity['type'] ?? null;

        if ($this->replies->handle($activity, $sender) || ! is_string($type) || CoreActivities::owns($type)) {
            return;
        }

        foreach ($this->handlers($type) as $handler) {
            try {
                $handler->handle($activity, $sender->actorUri);
            } catch (Throwable $failure) {
                Log::warning('A plugin’s fediverse handler failed.', ['type' => $type, 'handler' => $handler::class, 'error' => $failure->getMessage()]);
            }
        }
    }

    /**
     * The plugins' handlers that take this type.
     *
     * @return list<ActivityHandler>
     */
    private function handlers(string $type): array
    {
        if (! $this->hooks->wants(CollectActivityHandlers::class)) {
            return [];
        }

        /** @var list<ActivityHandler> $handlers */
        $handlers = $this->hooks->collect(new CollectActivityHandlers);

        return array_values(array_filter($handlers, function (ActivityHandler $handler) use ($type): bool {
            try {
                return in_array($type, $handler->types(), true);
            } catch (Throwable) {
                return false;
            }
        }));
    }
}
