<?php

declare(strict_types=1);

namespace Teme\Contracts\StructuredData;

/**
 * One FAQ card in an article: its own ID, the @id core would give a node
 * for it, and its questions with their answers, in order. Core adds no FAQ
 * markup of its own; a plugin may build an FAQPage from it.
 *
 * @since 1.1.0
 */
final readonly class FaqCard
{
    /**
     * @param  string  $id  the card's own ID, kept through every export
     * @param  string  $nodeId  an absolute @id for a node about the card: the page's address and NodeIds::card('faq', $id)
     * @param  list<FaqPair>  $pairs
     *
     * @since 1.1.0
     */
    public function __construct(
        public string $id,
        public string $nodeId,
        public array $pairs,
    ) {}
}
