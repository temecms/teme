<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Models\User;

/**
 * An area that holds pages of its own, such as Settings, Tools or Reports.
 * New areas join a hub before they claim a top-level entry (I-221).
 */
interface NavHub
{
    /**
     * The relative address of the hub's first page this person may open, or
     * null when there is none and the entry should not show.
     */
    public function firstHrefFor(User $user): ?string;

    /**
     * The hub's pages this person may open, in the hub's order, each with
     * its key, label, icon, a line saying what it is for, and its relative
     * address, or a plugin's outside one marked external (I-3353). The
     * palette lists them as "{hub} › {page}" (I-220).
     *
     * @return list<array{key: string, label: string, icon: string, description: string, href: string, external?: bool, plugin?: string}>
     */
    public function pagesFor(User $user): array;
}
