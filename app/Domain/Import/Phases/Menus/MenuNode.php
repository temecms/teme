<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Menus;

/**
 * One item of a WordPress menu on its way to Teme (I-343): how the report
 * names it, its label, where it led on WordPress and what's under it; then,
 * once found, what it is on Teme, or a heading that leads nowhere.
 */
final readonly class MenuNode
{
    /** A WordPress post or page, by its ID. */
    public const string ITEM = 'item';

    /** A WordPress term, by its term ID and taxonomy. */
    public const string TERM = 'term';

    /** The site's home page. */
    public const string HOME = 'home';

    /** WordPress's page list: its published pages under the page $objectId, or every one for 0. */
    public const string PAGES = 'pages';

    /** A language switcher, which leads to the page's versions (I-3446). */
    public const string LANGUAGES = 'languages';

    /** An address, as WordPress gave it. */
    public const string ADDRESS = 'address';

    /**
     * @param  string  $name  how the report names it: “About”, or an item with no label
     * @param  string  $label  its label as plain words, '' for none
     * @param  bool  $ownLabel  whether the label is the item's own, not its target's title
     * @param  list<MenuNode>  $children
     * @param  array{kind: string, target_id: string|null, url: string|null, options?: array<string, bool>}|null  $item  what it is on Teme once found; null for nothing yet, nothing at all, or a heading
     */
    public function __construct(
        public string $name,
        public string $label,
        public bool $ownLabel,
        public string $leadsTo,
        public int $objectId = 0,
        public string $taxonomy = '',
        public string $address = '',
        public bool $newTab = false,
        public array $children = [],
        public ?array $item = null,
        public bool $heading = false,
    ) {}

    /**
     * The same item with these items under it.
     *
     * @param  list<MenuNode>  $children
     */
    public function with(array $children): self
    {
        return new self($this->name, $this->label, $this->ownLabel, $this->leadsTo, $this->objectId, $this->taxonomy, $this->address, $this->newTab, $children, $this->item, $this->heading);
    }

    /**
     * The same item, found: what it is on Teme, or, with no item, a heading
     * when it leads nowhere and something left out when it can't be kept.
     *
     * @param  array{kind: string, target_id: string|null, url: string|null, options?: array<string, bool>}|null  $item
     */
    public function found(?array $item, bool $heading = false): self
    {
        return new self($this->name, $this->label, $this->ownLabel, $this->leadsTo, $this->objectId, $this->taxonomy, $this->address, $this->newTab, $this->children, $item, $heading);
    }

    /**
     * Returns how many items it is, with every item under it.
     */
    public function count(): int
    {
        $count = 1;

        foreach ($this->children as $child) {
            $count += $child->count();
        }

        return $count;
    }
}
