<?php

/**
 * Turns an item's content into the block tree the companion answers with:
 * WordPress's block grammar parsed by BlockMarkup, and each classic chunk
 * with visible text given its paragraphs by Paragraphs, under `autop`.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

use Closure;

final readonly class ContentBlocks
{
    /**
     * @param  Closure(string): list<array<string, mixed>>  $parse
     * @param  Closure(string): string  $paragraphs
     */
    private function __construct(private Closure $parse, private Closure $paragraphs) {}

    /**
     * The tree as BlockMarkup::parse() and Paragraphs::make() give it.
     */
    public static function wordPress(): self
    {
        return new self(BlockMarkup::parse(...), Paragraphs::make(...));
    }

    /**
     * A tree made by other hands, as tests give it.
     *
     * @param  Closure(string): list<array<string, mixed>>  $parse
     * @param  Closure(string): string  $paragraphs
     */
    public static function using(Closure $parse, Closure $paragraphs): self
    {
        return new self($parse, $paragraphs);
    }

    /**
     * Returns the content's blocks as the parser gives them, as the companion sends block navigation.
     *
     * @return list<array<string, mixed>>
     */
    public function parsed(string $content): array
    {
        return ($this->parse)($content);
    }

    /**
     * Returns the content's blocks, each classic chunk with visible text carrying its paragraphs under `autop`.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(string $content): array
    {
        return $this->annotated(($this->parse)($content));
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     *
     * @return list<array<string, mixed>>
     */
    private function annotated(array $blocks): array
    {
        foreach ($blocks as $at => $block) {
            if (($block['blockName'] ?? null) === null) {
                $html = is_string($block['innerHTML'] ?? null) ? $block['innerHTML'] : '';

                if (trim($html) !== '') {
                    $blocks[$at]['autop'] = ($this->paragraphs)($html);
                }

                continue;
            }

            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                /** @var list<array<string, mixed>> $inner */
                $inner = array_values($block['innerBlocks']);
                $blocks[$at]['innerBlocks'] = $this->annotated($inner);
            }
        }

        return $blocks;
    }
}
