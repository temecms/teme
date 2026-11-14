<?php

declare(strict_types=1);

namespace App\Domain\Cards\Queries;

use App\Domain\Cards\CardReference;
use App\Domain\Cards\Imported\MarkupTree;
use App\Domain\Cards\PlainText;
use App\Domain\Cards\References;
use Illuminate\Support\Facades\DB;

/**
 * What became of each Imported content card an import put in an article,
 * for the import's report (I-329). Read from the documents: Convert, Keep
 * and Delete are edits a writer saves, and leave no record of their own.
 */
final readonly class ImportedContentOutcomes
{
    /** The share of a gone card's words still in the article for it to count as converted: I-337's 2%, the other way. */
    public const float KEPT_WORDS = 0.98;

    /**
     * One row per Imported content card in each item's newest imported
     * revision, by item and then in the card's order: the item's ID, the
     * card's node ID, what it holds, where it came from, and its outcome,
     * `waiting`, `kept`, `converted` or `deleted`. An item with no imported
     * revision gives no row. One statement for every item.
     *
     * @param  list<string>  $contentIds
     *
     * @return list<array{contentId: string, cardId: string, holds: string, sourceKind: string, sourceName: string|null, outcome: string}>
     */
    public function of(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        $rows = DB::select(
            'SELECT c.id AS content_id, i.document AS imported, w.document AS working
             FROM contents c
             JOIN content_revisions w ON w.id = c.working_revision_id
             JOIN LATERAL (
                 SELECT r.document FROM content_revisions r
                 WHERE r.content_id = c.id AND r.origin = ?
                 ORDER BY r.created_at DESC, r.id DESC
                 LIMIT 1
             ) i ON true
             WHERE c.id IN ('.implode(', ', array_fill(0, count($contentIds), '?')).')
             ORDER BY c.id',
            ['import', ...$contentIds],
        );

        $outcomes = [];

        foreach ($rows as $row) {
            $imported = json_decode((string) $row->imported, true);
            $working = json_decode((string) $row->working, true);
            $working = is_array($working) ? $working : [];
            $now = $this->cards($working);
            // The article without its Imported content cards: a gone card's words must survive as prose, not in another card.
            $prose = ['type' => 'doc', 'content' => array_values(array_filter((array) ($working['content'] ?? []), fn (mixed $node): bool => ! is_array($node) || ($node['type'] ?? null) !== 'imported'))];
            $words = self::bag(PlainText::of($prose));
            $pictures = self::pictures($prose);

            foreach ($this->cards(is_array($imported) ? $imported : []) as $id => $attrs) {
                $outcome = match (true) {
                    isset($now[$id]) => ($now[$id]['kept'] ?? false) === true ? 'kept' : 'waiting',
                    $this->survived($attrs, $words, $pictures) => 'converted',
                    default => 'deleted',
                };

                $outcomes[] = [
                    'contentId' => (string) $row->content_id,
                    'cardId' => (string) $id,
                    'holds' => MarkupTree::holds($attrs['markup'] ?? []),
                    'sourceKind' => is_string($attrs['sourceKind'] ?? null) ? $attrs['sourceKind'] : '',
                    'sourceName' => is_string($attrs['sourceName'] ?? null) ? $attrs['sourceName'] : null,
                    'outcome' => $outcome,
                ];
            }
        }

        return $outcomes;
    }

    /**
     * The document's Imported content cards, by node ID, in order.
     *
     * @param  array<mixed>  $document
     *
     * @return array<string, array<mixed>>
     */
    private function cards(array $document): array
    {
        $cards = [];

        foreach ((array) ($document['content'] ?? []) as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'imported' && is_array($node['attrs'] ?? null) && is_string($node['attrs']['id'] ?? null)) {
                $cards[$node['attrs']['id']] = $node['attrs'];
            }
        }

        return $cards;
    }

    /**
     * Whether a card that has gone left itself in the article: at least
     * KEPT_WORDS of its words, counted with their repeats; for a card of
     * pictures alone, every picture.
     *
     * @param  array<mixed>  $attrs
     * @param  array<array-key, int>  $words
     * @param  list<string>  $pictures
     */
    private function survived(array $attrs, array $words, array $pictures): bool
    {
        $card = self::bag(MarkupTree::text($attrs['markup'] ?? []));
        $total = array_sum($card);

        if ($total === 0) {
            $wanted = array_map(fn (CardReference $reference): string => $reference->id, array_filter(MarkupTree::references($attrs['markup'] ?? []), fn (CardReference $reference): bool => $reference->kind === 'media'));

            return $wanted !== [] && array_diff($wanted, $pictures) === [];
        }

        $found = 0;

        foreach ($card as $word => $count) {
            $found += min($count, $words[$word] ?? 0);
        }

        return $found / $total >= self::KEPT_WORDS;
    }

    /**
     * Each word, lower-cased, with how often it appears.
     *
     * @return array<array-key, int>
     */
    private static function bag(string $text): array
    {
        return array_count_values(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * The library media the document shows, by ID.
     *
     * @param  array<mixed>  $document
     *
     * @return list<string>
     */
    private static function pictures(array $document): array
    {
        $ids = [];

        foreach (References::of($document) as $reference) {
            if ($reference->kind === 'media') {
                $ids[] = $reference->id;
            }
        }

        return $ids;
    }
}
