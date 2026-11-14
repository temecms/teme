<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;

/**
 * Yoast's and Rank Math's FAQ blocks as headings and paragraphs (I-335):
 * each question a level-3 heading, each answer its blocks, in order. A
 * block with no question found is read as any HTML.
 */
final readonly class Faq implements BlockMapping
{
    /** For each block, its sections' selector, then the question's and the answer's within one. */
    private const array PARTS = [
        'yoast/faq-block' => ['.schema-faq-section', '.schema-faq-question', '.schema-faq-answer'],
        'rank-math/faq-block' => ['.rank-math-list-item', '.rank-math-question', '.rank-math-answer'],
    ];

    public function blocks(): array
    {
        return array_keys(self::PARTS);
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        $root = $context->fragment($block->innerHtml);
        [$sections, $question, $answer] = self::PARTS[(string) $block->name] ?? self::PARTS['yoast/faq-block'];
        $nodes = [];

        foreach (iterator_to_array($root->querySelectorAll($sections)) as $section) {
            $asked = $section->querySelector($question);
            $answered = $section->querySelector($answer);

            if ($asked !== null) {
                array_push($nodes, ...$context->textblock('heading', $asked, Nodes::headingAttrs(3, $context->newId(), $asked)));
            }

            if ($answered !== null) {
                array_push($nodes, ...$context->read($answered));
            }
        }

        return $nodes === [] ? $context->read($root) : $nodes;
    }
}
