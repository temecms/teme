<?php

/**
 * A plugin's content.read door (docs/plans/plugin-sockets-3.md): published,
 * public items for anyone, a published public item's card, and an item's
 * draft and the queue of unpublished items only for the person the work acts
 * for, as far as they may edit them.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Queries\ItemCards;
use App\Domain\Contents\Queries\ItemSnapshots;
use App\Domain\Contents\Queries\PublishedItems;
use App\Domain\Contents\Queries\QueuedItems;
use App\Domain\Users\Actors;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemCard;
use Teme\Contracts\Content\ItemPage;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemSnapshot;
use Teme\Contracts\Content\QueuePage;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Content\WriteRefused;

final readonly class PluginContentReader implements ContentReader
{
    public function __construct(
        private PublishedItems $published,
        private ItemSnapshots $snapshots,
        private QueuedItems $queued,
        private ItemCards $cards,
    ) {}

    public function published(ItemQuery $query): ItemPage
    {
        return $this->published->page($query);
    }

    public function draft(string $itemId, string $actingPersonId): ItemSnapshot
    {
        try {
            ActingPerson::require($actingPersonId);
        } catch (WriteRefused $refused) {
            throw new ReadRefused($refused->getMessage());
        }

        $person = app(Actors::class)->find($actingPersonId);
        $content = Content::query()->find($itemId);

        if ($person === null || $content === null || $content->working_revision_id === null || ! Gate::forUser($person)->allows('update', $content)) {
            throw new ReadRefused(__('You can’t edit this item, so its draft can’t be read for you.'));
        }

        return $this->snapshots->of($content);
    }

    public function queue(QueueQuery $query, string $actingPersonId): QueuePage
    {
        try {
            ActingPerson::require($actingPersonId);
        } catch (WriteRefused $refused) {
            throw new ReadRefused($refused->getMessage());
        }

        $person = app(Actors::class)->find($actingPersonId);

        if ($person === null) {
            throw new ReadRefused(__('A plugin writes only for the person who started this work.'));
        }

        return $this->queued->page($query, $person);
    }

    public function card(string $itemId): ItemCard
    {
        return $this->cards->of($itemId);
    }
}
