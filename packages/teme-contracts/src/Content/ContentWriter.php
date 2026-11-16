<?php

declare(strict_types=1);

namespace Teme\Contracts\Content;

use DateTimeImmutable;

/**
 * How a plugin granted content.write creates and changes items, publishes
 * and schedules them, always on behalf of a named person.
 *
 * @since 0.9.0
 */
interface ContentWriter
{
    /**
     * Creates a draft and returns it.
     *
     * @throws WriteRefused
     *
     * @since 0.9.0
     */
    public function create(NewItem $item, string $actingPersonId): ItemRef;

    /**
     * Saves a new revision of the item's draft with these changes.
     *
     * @throws WriteRefused
     *
     * @since 0.9.0
     */
    public function change(ItemRef $item, ItemChanges $changes, string $actingPersonId): ItemRef;

    /**
     * Publishes the item's latest revision now.
     *
     * @throws WriteRefused
     *
     * @since 0.9.0
     */
    public function publish(ItemRef $item, string $actingPersonId): ItemRef;

    /**
     * Schedules the item's latest revision.
     *
     * @throws WriteRefused
     *
     * @since 0.9.0
     */
    public function schedule(ItemRef $item, DateTimeImmutable $at, string $actingPersonId): ItemRef;
}
