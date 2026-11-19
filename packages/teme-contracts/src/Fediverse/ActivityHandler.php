<?php

/**
 * A plugin's handler for activities of the types it names, such as Like.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

/**
 * A plugin's handler for activities of the types it names, such as Like.
 * It runs in the queued job that takes the activity, after
 * ActivityReceived, and writes only through the plugin's own Operations.
 * Types Teme takes itself (Follow, Accept, Reject, Undo, Create, Update,
 * Delete, Block, Move, Flag) are never given to a handler.
 *
 * @since 1.0.0
 */
interface ActivityHandler
{
    /**
     * The activity types this handler takes.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public function types(): array;

    /**
     * Takes one activity, signed by the account at $actorUri.
     *
     * @param  array<string, mixed>  $activity
     *
     * @since 1.0.0
     */
    public function handle(array $activity, string $actorUri): void;
}
