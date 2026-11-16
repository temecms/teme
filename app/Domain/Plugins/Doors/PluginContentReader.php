<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Doors;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Queries\ItemSnapshots;
use App\Domain\Contents\Queries\PublishedItems;
use App\Domain\Users\Actors;
use Illuminate\Support\Facades\Gate;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemPage;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemSnapshot;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Content\WriteRefused;

/**
 * A plugin's content.read door (docs/plans/plugin-sockets-3.md): published,
 * public items for anyone, and an item's draft only for the person the
 * work acts for, when they may edit it.
 */
final readonly class PluginContentReader implements ContentReader
{
    public function __construct(
        private PublishedItems $published,
        private ItemSnapshots $snapshots,
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
}
