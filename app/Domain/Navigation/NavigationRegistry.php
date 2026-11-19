<?php

/**
 * Every entry in the admin's main menu, core's included, so a plugin's entry
 * in phase 5 is just another registration (I-220).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Navigation;

use InvalidArgumentException;

final class NavigationRegistry
{
    /** @var array<string, NavEntry> */
    private array $entries = [];

    /** @var array<string, NavHub> */
    private array $hubs = [];

    public function register(NavEntry $entry): void
    {
        // Later (I-216, I-220): plugins register entries in phase 5 with their
        // package name as owner, within a site-wide cap on top-level entries,
        // and a badge provider for counts that need action.
        if (array_key_exists($entry->key, $this->entries)) {
            throw new InvalidArgumentException("A menu entry [{$entry->key}] is already registered.");
        }

        if ($entry->route === null && $entry->hub === null && ! $entry->soon) {
            throw new InvalidArgumentException("The menu entry [{$entry->key}] needs a route or a hub.");
        }

        if ($entry->parent !== null) {
            $parent = $this->entries[$entry->parent] ?? throw new InvalidArgumentException("The menu entry [{$entry->key}] names an unknown parent [{$entry->parent}].");

            if ($parent->parent !== null) {
                throw new InvalidArgumentException("The menu entry [{$entry->key}] would be a third level; that belongs inside the page.");
            }
        }

        $this->entries[$entry->key] = $entry;
    }

    public function hub(string $key, NavHub $hub): void
    {
        $this->hubs[$key] = $hub;
    }

    /**
     * Every registered entry, in registration order.
     *
     * @return list<NavEntry>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }

    /**
     * The hub registered under $key, or null.
     */
    public function hubFor(string $key): ?NavHub
    {
        return $this->hubs[$key] ?? null;
    }
}
