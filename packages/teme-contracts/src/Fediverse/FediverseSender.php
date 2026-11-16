<?php

declare(strict_types=1);

namespace Teme\Contracts\Fediverse;

/**
 * Sends an activity to the followers of one of the site's accounts, for a
 * plugin granted fediverse.send: Teme stores it, then signs and delivers
 * it once the plugin's work commits. A plugin never holds a key.
 *
 * @since 0.9.0
 */
interface FediverseSender
{
    /**
     * Queues an activity of $type carrying $object, sent by the site's
     * account at $actorUri, or by the blog's when null, and returns the
     * activity's address. Types Teme sends itself (Follow, Accept, Reject,
     * Undo, Create, Update, Delete, Block, Move, Flag) and objects Teme
     * owns are refused.
     *
     * @param  array<string, mixed>|string  $object
     *
     * @throws SendRefused when Teme will not send it
     *
     * @since 0.9.0
     */
    public function send(string $type, array|string $object, ?string $actorUri = null): string;
}
