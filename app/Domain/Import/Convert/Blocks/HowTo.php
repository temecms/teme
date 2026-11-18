<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Blocks;

use App\Domain\Cards\Types\StepsCard;
use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\BlockMapping;
use App\Domain\Import\Convert\Html\CardText;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\ParsedBlock;
use Dom\Element;

/**
 * Yoast's and Rank Math's how-to blocks as Teme's Steps card: the
 * description as its summary, the block's days, hours and minutes as its
 * total time, and each step's name, text and first image. A block whose
 * steps hold what a card's text can't becomes headings and paragraphs
 * instead, each step's name a level-3 heading, so nothing is lost; a block
 * with no step found is read as any HTML.
 */
final readonly class HowTo implements BlockMapping
{
    /** For each block: its description's selector, its steps', and a step's name and text within one. */
    private const array PARTS = [
        'yoast/how-to-block' => ['.schema-how-to-description', '.schema-how-to-step', '.schema-how-to-step-name', '.schema-how-to-step-text'],
        'rank-math/howto-block' => ['.rank-math-howto-description', '.rank-math-step', '.rank-math-step-title', '.rank-math-step-content'],
    ];

    public function blocks(): array
    {
        return array_keys(self::PARTS);
    }

    public function convert(ParsedBlock $block, BlockContext $context): array
    {
        $root = $context->fragment($block->innerHtml);
        [$described, $stepped, $named, $written] = self::PARTS[(string) $block->name] ?? self::PARTS['yoast/how-to-block'];
        $description = $root->querySelector($described);
        // Read before its blocks are, which takes them out of the tree.
        $summary = $description === null ? '' : HtmlFragment::text($description);
        $nodes = $description === null ? [] : $context->read($description);
        $steps = [];
        $fits = true;

        foreach (iterator_to_array($root->querySelectorAll($stepped)) as $step) {
            $name = $step->querySelector($named);
            $text = $step->querySelector($written);
            $image = $step->querySelector('img');
            $mediaId = null;

            if ($image instanceof Element) {
                $card = $context->imageCard($image);
                $mediaId = ($card[0]['type'] ?? null) === 'image' ? $card[0]['attrs']['mediaId'] : null;
                $fits = $fits && $mediaId !== null;
                array_push($nodes, ...$card);
                $image->remove();
            }

            $title = $name === null ? '' : HtmlFragment::text($name);
            $blocks = $text === null ? [] : $context->read($text);

            if ($name !== null) {
                array_push($nodes, ...$context->textblock('heading', $name, Nodes::headingAttrs(3, $context->newId(), $name)));
            }

            array_push($nodes, ...$blocks);
            $body = CardText::of($blocks);

            if ($title === '' || mb_strlen($title) > 200 || $body === null) {
                $fits = false;

                continue;
            }

            $steps[] = ['id' => $context->newId(), 'title' => $title, 'body' => $body, 'mediaId' => $mediaId];
        }

        if ($steps === []) {
            return $nodes === [] ? $context->read($root) : $nodes;
        }

        if (! $fits || count($steps) > StepsCard::MOST_STEPS || mb_strlen($summary) > 1000) {
            return $nodes;
        }

        return [['type' => 'steps', 'attrs' => [
            'id' => $context->newId(),
            'summary' => $summary === '' ? null : $summary,
            'totalTime' => self::duration($block->attrs),
            'materials' => self::lines($block->attrs['supply'] ?? $block->attrs['materials'] ?? null),
            'tools' => self::lines($block->attrs['tools'] ?? null),
            'steps' => $steps,
        ]]];
    }

    /**
     * The block's days, hours and minutes as an ISO 8601 duration, or null
     * when it gives none.
     *
     * @param  array<string, mixed>  $attrs
     */
    private static function duration(array $attrs): ?string
    {
        if (($attrs['hasDuration'] ?? false) !== true) {
            return null;
        }

        $parts = [];

        foreach (['days' => 'D', 'hours' => 'H', 'minutes' => 'M'] as $key => $unit) {
            $value = $attrs[$key] ?? null;
            $parts[$unit] = is_numeric($value) ? max(0, (int) $value) : 0;
        }

        $time = ($parts['H'] > 0 ? $parts['H'].'H' : '').($parts['M'] > 0 ? $parts['M'].'M' : '');
        $duration = 'P'.($parts['D'] > 0 ? $parts['D'].'D' : '').($time === '' ? '' : 'T'.$time);

        return $duration !== 'P' && StepsCard::duration($duration) !== null ? $duration : null;
    }

    /**
     * A list the block keeps as text, one thing a line or between commas.
     *
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $things = preg_split('/\r?\n|,/', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $value))) ?: [];

        return array_slice(array_values(array_filter(
            array_map(fn (string $thing): string => mb_substr(HtmlFragment::words($thing), 0, 200), $things),
            fn (string $thing): bool => $thing !== '',
        )), 0, StepsCard::MOST_THINGS);
    }
}
