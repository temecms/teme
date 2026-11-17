<?php

declare(strict_types=1);

namespace Teme\Seo\Jobs;

use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\ItemIndex;

/**
 * Reads the site into Teme SEO's index, as the person the run acts for: the
 * published items it hasn't read, the drafts that person may edit, and the
 * items a change marked to be read again. From the settings page's button,
 * it reads every item again.
 */
final readonly class IndexSite implements JobHandler
{
    /** Items read in one run at most; the next pass reads the rest. */
    private const int MOST = 2000;

    public function __construct(
        private ContentReader $content,
        private Indexer $indexer,
        private ItemIndex $index,
    ) {}

    public function handle(JobContext $context): void
    {
        $person = $context->personId();
        $everything = ($context->payload()['action'] ?? null) === 'read-links';
        $wanted = [];

        $after = null;

        do {
            $page = $this->content->published(new ItemQuery(after: $after, limit: 200));

            foreach ($page->items as $item) {
                $read = $this->index->isRead($item->id);
                $this->index->remember($item, stale: $everything || ! $read);

                if ($everything || ! $read) {
                    $wanted[$item->id] = true;
                }
            }

            $after = $page->next;
        } while ($after !== null && count($wanted) < self::MOST);

        $after = null;

        do {
            $queue = $this->content->queue(new QueueQuery(after: $after, limit: 200), $person);

            foreach ($queue->items as $queued) {
                if ($everything || ! $this->index->isRead($queued->item->id)) {
                    $this->index->remember($queued->item);
                    $wanted[$queued->item->id] = true;
                }
            }

            $after = $queue->next;
        } while ($after !== null && count($wanted) < self::MOST);

        foreach ($this->index->staleIds(self::MOST) as $itemId) {
            $wanted[$itemId] = true;
        }

        $ids = array_map(strval(...), array_slice(array_keys($wanted), 0, self::MOST));
        $total = count($ids);

        foreach ($ids as $done => $itemId) {
            if ($done % 25 === 0) {
                $context->progress($done, $total, __('Reading items'));
            }

            try {
                $this->indexer->read($itemId, $person);
            } catch (ReadRefused) {
                // An item this person may not edit waits for someone who may.
            }
        }

        $context->progress($total, $total, __('Items read: :count.', ['count' => $total]));
    }
}
