<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Menus\Operations\SaveNavigationItems;

/**
 * A found WordPress menu made into the tree a Teme menu holds (I-343): an
 * item with no address over items becomes a heading, an item left out
 * gives its place to the items under it, items deeper than three levels
 * sit at the third after the item they were under, and the first 200 items
 * in reading order are kept. Reads nothing.
 */
final class MenuShape
{
    /** The menu item that leads nowhere and opens its sub-menu. */
    private const string HEADING = 'heading';

    /**
     * The tree SaveNavigationItems takes, and what the report says of it.
     *
     * @param  list<MenuNode>  $nodes  found by MenuTargets
     *
     * @return array{tree: list<array{kind: string, target_id: string|null, url: string|null, label: string|null, opens_new_tab: bool, children: list<mixed>}>, messages: list<ImportMessage>}
     */
    public static function tree(array $nodes): array
    {
        $messages = [];
        $settled = self::settle($nodes, $messages);
        [$within, $lifted] = self::within($settled, 1);

        if ($lifted > 0) {
            $messages[] = new ImportMessage('menu.too-deep', $lifted === 1
                ? '1 item sat more than three levels deep, so it sits at the third level, after the item above it: a Teme menu goes three levels deep.'
                : "{$lifted} items sat more than three levels deep, so each sits at the third level, after the item above it: a Teme menu goes three levels deep.", ['count' => $lifted]);
        }

        $tally = ['kept' => 0, 'left' => 0, 'first' => null];
        $kept = self::capped($within, $tally);

        if ($tally['left'] > 0) {
            $total = $tally['kept'] + $tally['left'];
            $first = (string) $tally['first'];
            $messages[] = new ImportMessage('menu.too-long', $tally['left'] === 1
                ? "It has {$total} items, and a Teme menu holds 200, so the last, {$first}, was left out."
                : "It has {$total} items, and a Teme menu holds 200, so the last {$tally['left']} were left out, from {$first} on.", ['total' => $total, 'count' => $tally['left'], 'first' => $first]);
        }

        return ['tree' => array_map(self::row(...), $kept), 'messages' => $messages];
    }

    /**
     * Items with no address made headings over their items, or left out
     * with nothing under them, and items left out replaced by the items
     * under them.
     *
     * @param  list<MenuNode>  $nodes
     * @param  list<ImportMessage>  $messages
     *
     * @return list<MenuNode>
     */
    private static function settle(array $nodes, array &$messages): array
    {
        $settled = [];

        foreach ($nodes as $node) {
            $children = self::settle($node->children, $messages);

            if ($node->item !== null) {
                $settled[] = $node->with($children);

                continue;
            }

            if (! $node->heading) {
                // Said where it was found; the items under it take its place.
                array_push($settled, ...$children);

                continue;
            }

            if ($children === []) {
                $messages[] = new ImportMessage('menu.no-address', ucfirst($node->name).' had no address and nothing under it to lead to, so it was left out.');

                continue;
            }

            // A heading has a label (I-2628): without one, the items under it take its place.
            if ($node->label === '') {
                $messages[] = new ImportMessage('menu.heading-unlabelled', ucfirst($node->name).' had no address and no label, so the items under it take its place.');
                array_push($settled, ...$children);

                continue;
            }

            $settled[] = $node->with($children)->found(['kind' => self::HEADING, 'target_id' => null, 'url' => null]);
        }

        return $settled;
    }

    /**
     * Whether a settled item is a heading.
     */
    private static function isHeading(MenuNode $node): bool
    {
        return ($node->item['kind'] ?? null) === self::HEADING;
    }

    /**
     * The tree at most three levels deep: under a third-level item, every
     * item below it follows it at the third level, in reading order. A
     * heading at the third level could open nothing there, so the items
     * under it take its place.
     *
     * @param  list<MenuNode>  $nodes
     *
     * @return array{0: list<MenuNode>, 1: int} the nodes, and how many moved
     */
    private static function within(array $nodes, int $level): array
    {
        $within = [];
        $lifted = 0;

        foreach ($nodes as $node) {
            if ($level < SaveNavigationItems::MAX_DEPTH) {
                [$children, $count] = self::within($node->children, $level + 1);
                $within[] = $node->with($children);
                $lifted += $count;

                continue;
            }

            $below = self::reading($node->children);

            if (! self::isHeading($node)) {
                $within[] = $node->with([]);
            }

            array_push($within, ...$below);
            $lifted += count($below);
        }

        return [$within, $lifted];
    }

    /**
     * Every item under these, in reading order, each without its own; a
     * heading, which would open nothing, gives way to the items under it.
     *
     * @param  list<MenuNode>  $nodes
     *
     * @return list<MenuNode>
     */
    private static function reading(array $nodes): array
    {
        $flat = [];

        foreach ($nodes as $node) {
            if (! self::isHeading($node)) {
                $flat[] = $node->with([]);
            }

            array_push($flat, ...self::reading($node->children));
        }

        return $flat;
    }

    /**
     * The first 200 items in reading order, each under its own parent, a
     * parent always before the items under it, and a heading only when at
     * least one of its items is kept too; the rest counted, with the first
     * of them.
     *
     * @param  list<MenuNode>  $nodes
     * @param  array{kept: int, left: int, first: string|null}  $tally
     *
     * @return list<MenuNode>
     */
    private static function capped(array $nodes, array &$tally): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if ($tally['kept'] >= SaveNavigationItems::MAX_ITEMS) {
                $tally['left'] += $node->count();
                $tally['first'] ??= $node->name;

                continue;
            }

            $before = $tally;
            $tally['kept']++;
            $children = self::capped($node->children, $tally);

            // A heading with nothing under it is refused (I-2715): it goes with its items.
            if ($children === [] && self::isHeading($node)) {
                $tally = $before;
                $tally['left'] += $node->count();
                $tally['first'] ??= $node->name;

                continue;
            }

            $kept[] = $node->with($children);
        }

        return $kept;
    }

    /**
     * One item as SaveNavigationItems takes it. An item by ID keeps no label
     * unless WordPress gave it one of its own, so it follows its target's
     * title; an address keeps WordPress's label, having no title.
     *
     * @return array{kind: string, target_id: string|null, url: string|null, label: string|null, opens_new_tab: bool, children: list<mixed>}
     */
    private static function row(MenuNode $node): array
    {
        /** @var array{kind: string, target_id: string|null, url: string|null} $item */
        $item = $node->item;
        $byId = in_array($item['kind'], ['content', 'term'], true);

        return [
            'kind' => $item['kind'],
            'target_id' => $item['target_id'],
            'url' => $item['url'],
            'label' => ($byId && ! $node->ownLabel) || $node->label === '' ? null : $node->label,
            'opens_new_tab' => $node->newTab,
            'children' => array_map(self::row(...), $node->children),
        ];
    }
}
