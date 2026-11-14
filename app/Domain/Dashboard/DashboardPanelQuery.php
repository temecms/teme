<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Types\ContentType;
use App\Models\User;

/**
 * What fills one dashboard panel: one bounded query, on an index made for
 * it, that reads only items of the types it is given.
 */
interface DashboardPanelQuery
{
    /**
     * At most `$limit` rows for the viewer: the item's ID and title, a line
     * in words, the editor's address, and the ISO time the rows are sorted by.
     *
     * @param  list<ContentType>  $types  the types whose items the viewer may see in this panel
     *
     * @return list<array{id: string, title: string, subtitle: string, url: string, at: string}>
     */
    public function rows(User $viewer, array $types, int $limit): array;
}
