<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * How a card offers itself in the editor's insert and slash menus. Declared
 * on the card so one registry feeds every way of inserting it (Ghost's
 * design, study L §2.8).
 */
final readonly class CardMenuEntry
{
    /**
     * @param  list<string>  $keywords  what someone might type to find it
     */
    public function __construct(
        public string $label,
        public string $group,
        public array $keywords,
        public string $icon,
        /** Whether + Insert and the slash menu offer it: false for a card only the importer adds (I-329). */
        public bool $inMenus = true,
    ) {}
}
