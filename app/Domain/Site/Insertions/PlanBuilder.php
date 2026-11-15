<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;
use Teme\Contracts\Insertions\Position;

/**
 * Maps each insertion to the top-level document index it is drawn before
 * (I-064): paragraphs and headings only count as top-level nodes, so a
 * point never falls inside a list, card, quote, table or code block.
 */
final class PlanBuilder
{
    /**
     * @param  array<mixed>  $document
     * @param  list<Insertion>  $insertions
     */
    public static function for(array $document, ArticleFacts $facts, array $insertions): InsertionPlan
    {
        unset($facts);

        $content = array_values(array_filter(
            (array) ($document['content'] ?? []),
            is_array(...),
        ));
        $paragraphs = [];
        $headings = [];

        foreach ($content as $i => $node) {
            match ($node['type'] ?? null) {
                'paragraph' => $paragraphs[] = $i,
                'heading' => $headings[(int) ($node['attrs']['level'] ?? 2)][] = $i,
                default => null,
            };
        }

        $end = count($content);
        /** @var array<int, list<string>> $at */
        $at = [];

        foreach ($insertions as $insertion) {
            foreach (self::indexes($insertion->at, $paragraphs, $headings, $end) as $index) {
                $at[$index][] = $insertion->html;
            }
        }

        ksort($at);

        return new InsertionPlan($at);
    }

    /**
     * @param  list<int>  $paragraphs
     * @param  array<int, list<int>>  $headings
     *
     * @return list<int>
     */
    private static function indexes(Position $at, array $paragraphs, array $headings, int $end): array
    {
        return match ($at->kind) {
            Position::BEFORE_CONTENT => [0],
            Position::AFTER_CONTENT => [$end],
            Position::AFTER_PARAGRAPH => self::afterParagraph($at->args['n'] ?? 0, $paragraphs),
            Position::EVERY_PARAGRAPHS => self::everyParagraphs($at->args['n'] ?? 0, min($at->args['max'] ?? Position::EVERY_PARAGRAPHS_MAX, Position::EVERY_PARAGRAPHS_MAX), $paragraphs),
            Position::BEFORE_HEADING => self::headingIndex($headings, $at->args['level'] ?? 2, $at->args['nth'] ?? 1, 0),
            Position::AFTER_HEADING => self::headingIndex($headings, $at->args['level'] ?? 2, $at->args['nth'] ?? 1, 1),
            Position::NEAR_END => count($paragraphs) >= 3 ? [(int) end($paragraphs)] : [],
            default => [],
        };
    }

    /**
     * @param  list<int>  $paragraphs
     *
     * @return list<int>
     */
    private static function afterParagraph(int $n, array $paragraphs): array
    {
        if ($n < 1 || ! isset($paragraphs[$n - 1])) {
            return [];
        }

        return [$paragraphs[$n - 1] + 1];
    }

    /**
     * @param  list<int>  $paragraphs
     *
     * @return list<int>
     */
    private static function everyParagraphs(int $n, int $max, array $paragraphs): array
    {
        if ($n < 1 || $max < 1) {
            return [];
        }

        $indexes = [];
        $drawn = 0;

        for ($k = $n; $k <= count($paragraphs) && $drawn < $max; $k += $n) {
            $indexes[] = $paragraphs[$k - 1] + 1;
            $drawn++;
        }

        return $indexes;
    }

    /**
     * @param  array<int, list<int>>  $headings
     *
     * @return list<int>
     */
    private static function headingIndex(array $headings, int $level, int $nth, int $after): array
    {
        $index = $headings[$level][$nth - 1] ?? null;

        if ($index === null) {
            return [];
        }

        return [$index + $after];
    }
}
