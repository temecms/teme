<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Terms\WordPressText;

/**
 * A WordPress menu read as one tree (I-343), whatever its shape: a classic
 * menu's items under their parents in WordPress's order, an item whose
 * parent it doesn't list at the top as WordPress shows it; or a navigation
 * post's link blocks. Reads nothing; says what a Teme menu can't keep. One
 * instance reads one menu at a time.
 */
final class WordPressMenus
{
    /** A classic item's type for a post or page, and a link block's kind for one. */
    private const string POST_TYPE = 'post_type';

    private const string BLOCK_POST_TYPE = 'post-type';

    /** A classic item's type, and a link block's kind, for a term. */
    private const string TAXONOMY = 'taxonomy';

    /** WordPress's tag taxonomy, which a link block names `tag`. */
    private const string BLOCK_TAG = 'tag';

    private const string POST_TAG = 'post_tag';

    private const string LINK = 'core/navigation-link';

    private const string SUBMENU = 'core/navigation-submenu';

    private const string HOME_LINK = 'core/home-link';

    private const string PAGE_LIST = 'core/page-list';

    /** What classic text outside any block is called. */
    private const string FREEFORM = 'core/freeform';

    /** A label or an address holding an email address, which a message never repeats. */
    /** The address of Polylang's language switcher. */
    public const string SWITCHER = '#pll_switcher';

    /** Polylang's switcher options Teme's switcher has, each true or false. */
    private const array SWITCHER_OPTIONS = ['dropdown', 'show_names', 'hide_current'];

    public const string EMAIL = '/[^\s@]+@[^\s@]+\.[^\s@]+/';

    /** How many block names a message lists. */
    private const int LISTED = 8;

    /** @var array{classes: int, titles: int, relations: int, descriptions: int, formatting: int} */
    private array $extras = ['classes' => 0, 'titles' => 0, 'relations' => 0, 'descriptions' => 0, 'formatting' => 0];

    /** @var array<int, true> the classic items already in the tree */
    private array $placed = [];

    /** @var array<string, int> the blocks left out, by name */
    private array $dropped = [];

    /** @var list<ImportMessage> */
    private array $messages = [];

    /**
     * A classic menu's items as a tree: each under its parent, siblings in
     * WordPress's order (its `order`, then the order they came in). A
     * broken item is left out; one whose parent isn't listed, or whose
     * parents loop, goes to the top after the rest, with what's under it.
     *
     * @param  array<mixed>  $items  the companion's items
     *
     * @return array{nodes: list<MenuNode>, messages: list<ImportMessage>}
     */
    public function classic(array $items): array
    {
        $this->start();
        $kept = [];

        foreach (array_values($items) as $index => $raw) {
            $id = is_array($raw) ? (int) ($raw['id'] ?? 0) : 0;

            if (! is_array($raw) || $id < 1 || isset($kept[$id])) {
                continue;
            }

            if (($raw['invalid'] ?? false) === true) {
                $name = self::name(self::label($raw['label'] ?? '')['text'], $id);
                $this->messages[] = new ImportMessage('menu.broken', "WordPress marked {$name} as broken, since what it led to is gone, so it was left out.", ['item' => $id]);

                continue;
            }

            $kept[$id] = ['raw' => $raw, 'sort' => [(int) ($raw['order'] ?? 0), $index]];
        }

        uasort($kept, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $under = [];

        foreach ($kept as $id => $entry) {
            $under[(int) ($entry['raw']['parent'] ?? 0)][] = $id;
        }

        $nodes = $this->branch(0, $under, $kept);

        foreach (array_keys($kept) as $id) {
            if (isset($this->placed[$id])) {
                continue;
            }

            // At the top, as WordPress's walker shows an item whose parent it doesn't draw.
            $this->placed[$id] = true;
            $node = $this->classicNode($id, $kept[$id]['raw'])->with($this->branch($id, $under, $kept));
            $nodes[] = $node;
            $this->messages[] = new ImportMessage('menu.orphan', ucfirst($node->name).' sat under an item WordPress’s menu doesn’t show, so it’s at the top of the menu, as WordPress showed it.', ['item' => $id]);
        }

        return ['nodes' => $nodes, 'messages' => $this->finished()];
    }

    /**
     * A navigation post's blocks as a tree: its links and submenus, its home
     * link and its page lists; a link with no label left out with what's
     * under it, as WordPress draws neither; every other block left out and
     * named.
     *
     * @param  array<mixed>  $blocks  parse_blocks()'s tree
     *
     * @return array{nodes: list<MenuNode>, messages: list<ImportMessage>}
     */
    public function blocks(array $blocks): array
    {
        $this->start();
        $nodes = $this->blockList($blocks);

        if ($this->dropped !== []) {
            $parts = [];

            foreach (array_slice($this->dropped, 0, self::LISTED, true) as $name => $count) {
                $short = mb_substr((string) $name, 0, 40);
                $parts[] = $count === 1 ? "1 {$short} block" : "{$count} {$short} blocks";
            }

            // At most eight names, so the words stay inside an import message's 1,000 characters.
            $more = count($this->dropped) - self::LISTED;
            $words = 'It also held '.self::joined($more > 0 ? [...$parts, "{$more} more"] : $parts).', which a Teme menu can’t hold, so '.(array_sum($this->dropped) === 1 ? 'it was' : 'they were').' left out.';
            $this->messages[] = new ImportMessage('menu.blocks-left-out', $words, [
                'blocks' => array_map(fn (string $name): string => mb_substr($name, 0, 40), array_slice(array_keys($this->dropped), 0, self::LISTED)),
                'counts' => array_slice(array_values($this->dropped), 0, self::LISTED),
            ]);
        }

        return ['nodes' => $nodes, 'messages' => $this->finished()];
    }

    /**
     * A label as WordPress showed it, as plain words: its tags removed,
     * WordPress's escaping undone once, runs of spaces as one, cut to the
     * 200 characters a menu's label holds; and whether it had formatting.
     *
     * @return array{text: string, formatted: bool}
     */
    public static function label(mixed $label): array
    {
        $raw = is_scalar($label) ? (string) $label : '';
        $plain = strip_tags($raw);
        $text = trim((string) preg_replace('/\s+/u', ' ', WordPressText::decoded($plain)));

        return ['text' => rtrim(mb_substr($text, 0, 200)), 'formatted' => $plain !== $raw];
    }

    /**
     * How a message names an item: its label in curly quotes; $none when it
     * has none; $email when the label holds an email address, which a
     * message never repeats.
     */
    public static function named(string $label, string $none, string $email): string
    {
        return match (true) {
            $label === '' => $none,
            preg_match(self::EMAIL, $label) === 1 => $email,
            default => "“{$label}”",
        };
    }

    /**
     * Words joined as a sentence lists them: "a", "a and b", "a, b and c".
     *
     * @param  list<string>  $parts
     */
    public static function joined(array $parts): string
    {
        $last = (string) array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }

    private function start(): void
    {
        $this->extras = ['classes' => 0, 'titles' => 0, 'relations' => 0, 'descriptions' => 0, 'formatting' => 0];
        $this->placed = [];
        $this->dropped = [];
        $this->messages = [];
    }

    /**
     * The items under $parent, each with its own under it; an item already
     * placed is skipped, so parents that loop can't recurse.
     *
     * @param  array<int, list<int>>  $under
     * @param  array<int, array{raw: array<mixed>, sort: array{0: int, 1: int}}>  $kept
     *
     * @return list<MenuNode>
     */
    private function branch(int $parent, array $under, array $kept): array
    {
        $nodes = [];

        foreach ($under[$parent] ?? [] as $id) {
            if (isset($this->placed[$id])) {
                continue;
            }

            $this->placed[$id] = true;
            $nodes[] = $this->classicNode($id, $kept[$id]['raw'])->with($this->branch($id, $under, $kept));
        }

        return $nodes;
    }

    /**
     * Polylang's language switcher as Teme's, found already: the options
     * Teme's switcher has, each true or false; any other that was on is
     * left out and said.
     *
     * @param  array<mixed>  $options
     */
    private function switcher(int $id, string $label, array $options): MenuNode
    {
        $kept = [];
        $dropped = [];

        foreach ($options as $option => $value) {
            $on = filter_var($value, FILTER_VALIDATE_BOOLEAN);

            if (in_array($option, self::SWITCHER_OPTIONS, true)) {
                $kept[(string) $option] = $on;
            } elseif ($on) {
                $dropped[] = (string) $option;
            }
        }

        $node = new MenuNode(name: self::name($label, $id), label: $label, ownLabel: true, leadsTo: MenuNode::LANGUAGES);

        if ($dropped !== []) {
            $this->messages[] = new ImportMessage('menu.switcher-options', __('Its language switcher had options Teme’s switcher doesn’t have, so they were left out: :options.', ['options' => implode(', ', $dropped)]), ['item' => $id, 'options' => $dropped]);
        }

        return $node->found(['kind' => MenuNode::LANGUAGES, 'target_id' => null, 'url' => null, 'options' => $kept]);
    }

    /**
     * One classic item as WordPress showed it, without what's under it.
     *
     * @param  array<mixed>  $raw
     */
    private function classicNode(int $id, array $raw): MenuNode
    {
        ['text' => $label, 'formatted' => $formatted] = self::label($raw['label'] ?? '');
        $classes = is_array($raw['classes'] ?? null) ? implode(' ', array_map(strval(...), array_filter($raw['classes'], is_scalar(...)))) : '';
        $this->counted($formatted, $classes, $raw['attr_title'] ?? '', $raw['xfn'] ?? '', '');
        $type = (string) ($raw['type'] ?? '');
        $objectId = (int) ($raw['object_id'] ?? 0);

        // Polylang's language switcher becomes Teme's (I-3446).
        if (($raw['url'] ?? null) === self::SWITCHER) {
            return $this->switcher($id, $label, is_array($raw['pll_switcher'] ?? null) ? $raw['pll_switcher'] : []);
        }

        $leadsTo = match (true) {
            $type === self::POST_TYPE && $objectId > 0 => MenuNode::ITEM,
            $type === self::TAXONOMY && $objectId > 0 => MenuNode::TERM,
            default => MenuNode::ADDRESS,
        };

        return new MenuNode(
            name: self::name($label, $id),
            label: $label,
            ownLabel: $leadsTo === MenuNode::ADDRESS || ($raw['label_is_custom'] ?? true) !== false,
            leadsTo: $leadsTo,
            objectId: $objectId,
            taxonomy: (string) ($raw['object'] ?? ''),
            address: (string) ($raw['url'] ?? ''),
            newTab: ($raw['target'] ?? '') === '_blank',
        );
    }

    /**
     * @param  array<mixed>  $blocks
     *
     * @return list<MenuNode>
     */
    private function blockList(array $blocks): array
    {
        $nodes = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : null;
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if ($name === self::LINK || $name === self::SUBMENU) {
                $node = $this->linkNode($attrs, is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : []);

                if ($node !== null) {
                    $nodes[] = $node;
                }
            } elseif ($name === self::HOME_LINK) {
                $label = self::label($attrs['label'] ?? '')['text'];
                $nodes[] = new MenuNode(self::named($label, '“Home”', 'a link labelled with an email address'), $label, true, MenuNode::HOME, newTab: ($attrs['opensInNewTab'] ?? false) === true);
            } elseif ($name === self::PAGE_LIST) {
                $nodes[] = new MenuNode('the page list', '', false, MenuNode::PAGES, max(0, (int) ($attrs['parentPageID'] ?? 0)));
            } elseif ($name !== null || trim(strip_tags((string) ($block['innerHTML'] ?? ''))) !== '') {
                // Whitespace between blocks is nothing; anything else is left out, named.
                $key = $name ?? self::FREEFORM;
                $this->dropped[$key] = ($this->dropped[$key] ?? 0) + 1;
            }
        }

        return $nodes;
    }

    /**
     * A link or submenu block with what's under it; null when it has no
     * label, so WordPress draws neither it nor what's under it.
     *
     * @param  array<mixed>  $attrs
     * @param  array<mixed>  $inner
     */
    private function linkNode(array $attrs, array $inner): ?MenuNode
    {
        ['text' => $label, 'formatted' => $formatted] = self::label($attrs['label'] ?? '');

        if ($label === '') {
            $this->messages[] = new ImportMessage('menu.unlabelled', 'A link with no label, which WordPress doesn’t show, was left out with anything under it.');

            return null;
        }

        $this->counted($formatted, $attrs['className'] ?? '', $attrs['title'] ?? '', $attrs['rel'] ?? '', $attrs['description'] ?? '');
        $kind = (string) ($attrs['kind'] ?? '');
        $type = (string) ($attrs['type'] ?? '');
        $id = (int) ($attrs['id'] ?? 0);

        return new MenuNode(
            name: self::named($label, '', 'a link labelled with an email address'),
            label: $label,
            ownLabel: true,
            leadsTo: match (true) {
                $kind === self::BLOCK_POST_TYPE && $id > 0 => MenuNode::ITEM,
                $kind === self::TAXONOMY && $id > 0 => MenuNode::TERM,
                default => MenuNode::ADDRESS,
            },
            objectId: $id,
            taxonomy: $type === self::BLOCK_TAG ? self::POST_TAG : $type,
            address: (string) ($attrs['url'] ?? ''),
            newTab: ($attrs['opensInNewTab'] ?? false) === true,
            children: $this->blockList($inner),
        );
    }

    /**
     * Counts what a Teme menu doesn't keep of one item.
     */
    private function counted(bool $formatted, mixed $classes, mixed $title, mixed $relation, mixed $description): void
    {
        foreach (['formatting' => $formatted, 'classes' => $classes, 'titles' => $title, 'relations' => $relation, 'descriptions' => $description] as $key => $value) {
            if ($value === true || (is_string($value) && trim($value) !== '')) {
                $this->extras[$key]++;
            }
        }
    }

    /**
     * The messages so far, and what Teme's menus don't keep, said once.
     *
     * @return list<ImportMessage>
     */
    private function finished(): array
    {
        $parts = [];

        foreach ([
            'classes' => ['the CSS classes of 1 item', 'the CSS classes of %d items'],
            'titles' => ['the link title of 1 item', 'the link titles of %d items'],
            'relations' => ['the link relation of 1 item', 'the link relations of %d items'],
            'descriptions' => ['the description of 1 item', 'the descriptions of %d items'],
            'formatting' => ['the formatting of 1 label', 'the formatting of %d labels'],
        ] as $key => [$one, $many]) {
            $count = $this->extras[$key];

            if ($count > 0) {
                $parts[] = $count === 1 ? $one : sprintf($many, $count);
            }
        }

        if ($parts === []) {
            return $this->messages;
        }

        $words = 'Teme’s menus keep each item’s label, where it leads and whether it opens a new tab, so these were left out: '.self::joined($parts).'.';

        return [...$this->messages, new ImportMessage('menu.extras', $words, $this->extras)];
    }

    /**
     * How the report names a classic item.
     */
    private static function name(string $label, int $id): string
    {
        return self::named($label, "an item with no label (WordPress ID {$id})", "an item labelled with an email address (WordPress ID {$id})");
    }
}
