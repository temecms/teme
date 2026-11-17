<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Cards\Types\FaqCard;
use App\Domain\Cards\Types\StepsCard;
use App\Domain\Feeds\AbsoluteLinks;
use App\Domain\Feeds\FeedUrls;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\StructuredData\FaqCard as FaqFacts;
use Teme\Contracts\StructuredData\FaqPair;
use Teme\Contracts\StructuredData\NodeIds;
use Teme\Contracts\StructuredData\Step;
use Teme\Contracts\StructuredData\StepsCard as StepsFacts;

/**
 * The FAQ and Steps cards in the revision a page shows, as the contracts
 * hand them to plugins that add structured data
 * (docs/guides/developers/plugins/seo.md): words as plain text, rich text
 * as HTML with every address absolute.
 */
final readonly class ArticleCards
{
    public function __construct(
        private CardMedia $media,
        private LinkResolver $links,
        private FeedUrls $urls,
    ) {}

    /**
     * The revision's FAQ cards and Steps cards, in the order they stand in
     * the body, nested ones included, each node @id under $address. Both
     * lists are empty when the revision has neither.
     *
     * @return array{0: list<FaqFacts>, 1: list<StepsFacts>}
     */
    public function for(string $revisionId, string $address): array
    {
        if ($revisionId === '') {
            return [[], []];
        }

        $stored = DB::table('content_revisions')->where('id', $revisionId)->value('document');
        $document = is_string($stored) ? (array) json_decode($stored, true) : [];
        $found = ['faq' => [], 'steps' => []];
        $this->walk((array) ($document['content'] ?? []), $found);

        if ($found['faq'] === [] && $found['steps'] === []) {
            return [[], []];
        }

        $context = new RenderContext(new AbsoluteLinks($this->links, $this->urls), 'feed', $this->media->forDocument($document));

        return [
            array_map(fn (array $attrs): FaqFacts => $this->faq($attrs, $address, $context), $found['faq']),
            array_map(fn (array $attrs): StepsFacts => $this->steps($attrs, $address, $context), $found['steps']),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $content
     * @param  array{faq: list<array<string, mixed>>, steps: list<array<string, mixed>>}  $found
     */
    private function walk(array $content, array &$found): void
    {
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;
            $attrs = (array) ($node['attrs'] ?? []);

            if (($type === 'faq' || $type === 'steps') && is_string($attrs['id'] ?? null)) {
                $found[$type][] = array_filter($attrs, fn (int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);

                continue;
            }

            $this->walk((array) ($node['content'] ?? []), $found);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function faq(array $attrs, string $address, RenderContext $context): FaqFacts
    {
        $pairs = [];

        foreach (FaqCard::items($attrs) as $item) {
            $answer = CardProse::text($item['answer']);

            if ($item['question'] !== '' && $answer !== '') {
                $pairs[] = new FaqPair($item['id'], $item['question'], $answer, CardProse::html($item['answer'], $context));
            }
        }

        $id = (string) $attrs['id'];

        return new FaqFacts($id, $address.NodeIds::card('faq', $id), $pairs);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function steps(array $attrs, string $address, RenderContext $context): StepsFacts
    {
        $steps = [];

        foreach (StepsCard::steps($attrs) as $step) {
            if ($step['title'] === '') {
                continue;
            }

            $steps[] = new Step(
                id: $step['id'],
                position: count($steps) + 1,
                title: $step['title'],
                text: CardProse::text($step['body']),
                html: CardProse::html($step['body'], $context),
                image: $this->image($context->media[$step['mediaId']] ?? null),
                url: $address.'#'.StepsCard::fragment($step['id']),
            );
        }

        $id = (string) $attrs['id'];
        $time = is_string($attrs['totalTime'] ?? null) && StepsCard::duration($attrs['totalTime']) !== null ? $attrs['totalTime'] : null;

        return new StepsFacts(
            id: $id,
            nodeId: $address.NodeIds::card('steps', $id),
            summary: trim((string) ($attrs['summary'] ?? '')),
            totalTime: $time,
            materials: StepsCard::things($attrs, 'materials'),
            tools: StepsCard::things($attrs, 'tools'),
            steps: $steps,
        );
    }

    /**
     * An image's widest version at an absolute address, or null for
     * anything that isn't a live image.
     *
     * @param  array{variants: array<string, array{url: string, width: int}>, kind: string}|null  $item
     */
    private function image(?array $item): ?string
    {
        if ($item === null || $item['kind'] !== 'image' || $item['variants'] === []) {
            return null;
        }

        $widths = array_column($item['variants'], 'width');
        $widest = array_values($item['variants'])[(int) array_search(max($widths), $widths, true)];

        return $this->urls->absolute($widest['url']);
    }
}
