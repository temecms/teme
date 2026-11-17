<?php

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Site\Insertions\PageInsertions;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Teme\Contracts\Insertions\PagePosition;

/**
 * `<x-teme::between-items :after="$loop->iteration" :last="$loop->last" />`:
 * what plugins place between a list's items, after this item, cleaned;
 * nothing after the last item, or where nothing is placed. A theme puts it
 * inside its list loops.
 */
final class BetweenItems extends Component
{
    /** @var list<string> the cleaned markup drawn after this item */
    public array $html = [];

    /**
     * @param  int  $after  the item just drawn, counted from 1
     * @param  bool  $last  whether it is the list's last item
     */
    public function __construct(PageInsertions $insertions, int $after, bool $last = false)
    {
        foreach ($insertions->at(PagePosition::AFTER_ITEM, PagePosition::EVERY_ITEMS) as $entry) {
            if (PageInsertions::afterItem($entry['insertion']->at, $after, $last)) {
                $html = $insertions->draw($entry);

                if (trim($html) !== '') {
                    $this->html[] = $html;
                }
            }
        }
    }

    public function shouldRender(): bool
    {
        return $this->html !== [];
    }

    public function render(): View
    {
        return view('teme::components.between-items');
    }
}
