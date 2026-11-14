<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * One kind of card, declared once (spec §5.5). From this single declaration
 * come the editor's menu entry, the stored attributes and their rules, the
 * HTML the site renders, the text search indexes, and the references the
 * projections record.
 */
interface CardType
{
    /**
     * The node type as it appears in the document, for example "image".
     */
    public function name(): string;

    public function label(): string;

    public function description(): string;

    /**
     * The release that introduced the card, for the generated reference.
     */
    public function since(): string;

    /**
     * @return array<string, CardAttribute>
     */
    public function attributes(): array;

    public function menu(): CardMenuEntry;

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function renderHtml(array $attrs, RenderContext $context): string;

    /**
     * The card's own words, for the search text and the word count.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function plainText(array $attrs): string;

    /**
     * @param  array<string, mixed>  $attrs
     *
     * @return list<CardReference>
     */
    public function references(array $attrs): array;

    /**
     * A check the attribute rules cannot express, such as "exactly one of
     * these two". Throws ValidationException; returns silently otherwise.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function validate(array $attrs): void;

    /**
     * One line saying how this card changed between two versions, for the
     * revision comparison: its label, a dash, and what changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string;
}
