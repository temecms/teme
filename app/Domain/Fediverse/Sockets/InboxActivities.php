<?php

/**
 * What the inbox's queued job does with an activity beyond following
 * (docs/plans/fediverse-publishing.md, D5): plugins granted fediverse.read
 * hear of every activity taken; a reply goes to the comments; any other
 * type Teme does not take itself goes to the plugins' handlers for it,
 * each run as tracked, queued work for the administrator who enabled its
 * plugin (follow-up R7).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Fediverse\Replies\InboxReplies;
use App\Domain\Fediverse\Signatures\VerifiedSender;
use App\Domain\Fediverse\Sockets\Jobs\RunActivityHandler;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Fediverse\ActivityHandler;
use Teme\Contracts\Fediverse\ActivityReceived;
use Teme\Contracts\Fediverse\CollectActivityHandlers;
use Throwable;

final readonly class InboxActivities
{
    /** The largest activity, with what goes with it, kept on a handler's job. */
    public const int MOST_BYTES = 65_536;

    public function __construct(
        private HookDispatcher $hooks,
        private InboxReplies $replies,
        private LoadedPlugins $loaded,
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
            $this->queue($handler, $activity, $sender->actorUri);
        }
    }

    /**
     * Runs the named plugin's handler of this class on the activity, as the
     * queued job asks; false when the plugin no longer offers it.
     *
     * @param  array<string, mixed>  $activity
     */
    public function runHandler(string $plugin, string $handlerClass, array $activity, string $actorUri): bool
    {
        $type = $activity['type'] ?? null;

        if (! is_string($type) || CoreActivities::owns($type)) {
            return false;
        }

        foreach ($this->handlers($type) as $handler) {
            if ($handler::class === $handlerClass && $this->owner($handler) === $plugin) {
                $handler->handle($activity, $actorUri);

                return true;
            }
        }

        return false;
    }

    /**
     * Queues one handler's run as tracked work, as the administrator who
     * enabled its plugin; a handler whose plugin can't be told, or that no
     * administrator enabled, is left out and logged.
     *
     * @param  array<string, mixed>  $activity
     */
    private function queue(ActivityHandler $handler, array $activity, string $actorUri): void
    {
        $plugin = $this->owner($handler);
        $record = $plugin === null ? null : PluginRecord::query()->where('name', $plugin)->where('state', PluginRecord::ENABLED)->first();

        if ($record === null || $record->consented_by === null) {
            Log::warning('A plugin’s fediverse handler was left out: its plugin could not be told, or no administrator enabled it.', ['handler' => $handler::class]);

            return;
        }

        $payload = ['plugin' => $record->name, 'handler' => $handler::class, 'activity' => $activity, 'actor' => $actorUri];

        if (strlen((string) json_encode($payload)) > self::MOST_BYTES) {
            Log::warning('A fediverse activity was too large to hand to a plugin.', ['handler' => $handler::class]);

            return;
        }

        $id = app(StartJob::class)->execute(new StartJobInput(
            kind: ActivityHandlerJobKind::KEY,
            label: __('Fediverse :type for :plugin', ['type' => (string) $activity['type'], 'plugin' => $record->code]),
            runType: ActivityHandlerJobKind::RUN_TYPE,
            runId: $record->code,
            actorId: (string) $record->consented_by,
            owner: $record->code,
            payload: $payload,
        ));

        RunActivityHandler::dispatch($id);
    }

    /**
     * The name of the running plugin the handler's class belongs to, by its
     * plugin class's namespace, or null.
     */
    private function owner(ActivityHandler $handler): ?string
    {
        foreach ($this->loaded->running() as $name => $plugin) {
            $last = strrpos($plugin::class, '\\');
            $namespace = $last === false ? '' : substr($plugin::class, 0, $last + 1);

            if ($namespace !== '' && str_starts_with($handler::class, $namespace)) {
                return $name;
            }
        }

        return null;
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
