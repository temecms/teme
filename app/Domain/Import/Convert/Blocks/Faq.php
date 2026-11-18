<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Cards\CardProse;
use App\Domain\Cards\Types\FaqCard;
use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\CardText;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;

/**
 * Yoast's and Rank Math's FAQ blocks as Teme's FAQ card: each question its
 * words, each answer its text. A block whose answers hold what a card's
 * text can't, such as an image, or with a question left unanswered, which
 * the card refuses, becomes headings and paragraphs instead,
 * each question a level-3 heading (I-335), so nothing is lost; a block with
 * no question found is read as any HTML.
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
        $items = [];
        $fits = true;

        foreach (iterator_to_array($root->querySelectorAll($sections)) as $section) {
            $asked = $section->querySelector($question);
            $answered = $section->querySelector($answer);
            $blocks = $answered === null ? [] : $context->read($answered);

            if ($asked !== null) {
                array_push($nodes, ...$context->textblock('heading', $asked, Nodes::headingAttrs(3, $context->newId(), $asked)));
            }

            array_push($nodes, ...$blocks);

            $words = $asked === null ? '' : HtmlFragment::text($asked);
            $text = CardText::of($blocks);

            if ($words === '' || mb_strlen($words) > FaqCard::MOST_QUESTION || $text === null || CardProse::text($text) === '') {
                $fits = false;

                continue;
            }

            $items[] = ['id' => $context->newId(), 'question' => $words, 'answer' => $text];
        }

        if ($nodes === []) {
            return $context->read($root);
        }

        if (! $fits || $items === [] || count($items) > FaqCard::MOST_ITEMS) {
            return $nodes;
        }

        return [['type' => 'faq', 'attrs' => ['id' => $context->newId(), 'items' => $items]]];
    }
}
