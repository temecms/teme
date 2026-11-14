<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\StructuredDataCard;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\ViewModels\ArticleView;
use Illuminate\Support\Facades\DB;

/**
 * The nodes an item's cards give for themselves (I-704), read from
 * their attributes in the revision the page shows. Nothing is read while no
 * registered card gives any.
 */
final readonly class CardNodes
{
    public function __construct(private CardRegistry $cards) {}

    /**
     * The nodes, in the order their cards stand in the body, nested cards
     * included.
     *
     * @return list<JsonLdNode>
     */
    public function for(ArticleView $view, string $page): array
    {
        $giving = array_filter($this->cards->all(), fn (CardType $card): bool => $card instanceof StructuredDataCard);

        if ($giving === [] || $view->revisionId === '') {
            return [];
        }

        $document = (array) json_decode((string) DB::table('content_revisions')->where('id', $view->revisionId)->value('document'), true);
        $nodes = [];
        $this->walk((array) ($document['content'] ?? []), $page, $nodes);

        return $nodes;
    }

    /**
     * @param  array<array-key, mixed>  $content
     * @param  list<JsonLdNode>  $nodes
     */
    private function walk(array $content, string $page, array &$nodes): void
    {
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }

            $card = is_string($node['type'] ?? null) ? $this->cards->find($node['type']) : null;

            if ($card instanceof StructuredDataCard) {
                array_push($nodes, ...$card->structuredData((array) ($node['attrs'] ?? []), $page));
            }

            $this->walk((array) ($node['content'] ?? []), $page, $nodes);
        }
    }
}
