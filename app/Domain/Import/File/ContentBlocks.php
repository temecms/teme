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
use LogicException;

final readonly class ContentBlocks
{
    /** The block markup parser, named so the reader builds before the parser's part joins it (docs/architecture/import.md). */
    private const string PARSER = 'App\\Domain\\Import\\File\\BlockMarkup';

    /** The classic paragraphs maker, likewise. */
    private const string PARAGRAPHS = 'App\\Domain\\Import\\File\\Paragraphs';

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
        $parse = [self::PARSER, 'parse'];
        $make = [self::PARAGRAPHS, 'make'];

        if (! is_callable($parse) || ! is_callable($make)) {
            throw new LogicException('The block markup parser isn’t part of this build.');
        }

        return new self(
            static function (string $html) use ($parse): array {
                /** @var list<array<string, mixed>> */
                return $parse($html);
            },
            static fn (string $html): string => (string) $make($html),
        );
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
