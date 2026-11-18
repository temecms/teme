<?php

/**
 * How a plugin granted content.write creates and changes items, publishes and
 * schedules them, and links language versions, always on behalf of a named
 * person.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use DateTimeImmutable;

/**
 * How a plugin granted content.write creates and changes items, publishes
 * and schedules them, and links language versions, always on behalf of a
 * named person.
 *
 * @since 1.0.0
 */
interface ContentWriter
{
    /**
     * Creates a draft and returns it.
     *
     * @throws WriteRefused
     *
     * @since 1.0.0
     */
    public function create(NewItem $item, string $actingPersonId): ItemRef;

    /**
     * Saves a new revision of the item's draft with these changes.
     *
     * @throws WriteRefused
     *
     * @since 1.0.0
     */
    public function change(ItemRef $item, ItemChanges $changes, string $actingPersonId): ItemRef;

    /**
     * Publishes the item's latest revision now.
     *
     * @throws WriteRefused
     *
     * @since 1.0.0
     */
    public function publish(ItemRef $item, string $actingPersonId): ItemRef;

    /**
     * Schedules the item's latest revision.
     *
     * @throws WriteRefused
     *
     * @since 1.0.0
     */
    public function schedule(ItemRef $item, DateTimeImmutable $at, string $actingPersonId): ItemRef;

    /**
     * Links existing items as language versions of one another: every
     * version each already has joins one group. Refused when two would share
     * a language, the types differ, or one is in the trash.
     *
     * @param  list<string>  $itemIds  2 to the site's number of languages
     *
     * @throws WriteRefused
     *
     * @since 1.0.0
     */
    public function linkTranslations(array $itemIds, string $actingPersonId): void;
}
