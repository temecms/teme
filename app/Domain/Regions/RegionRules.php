<?php

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Contents\Models\Content;
use App\Domain\Themes\ThemeRegistry;
use App\Domain\Types\CoreTypes;

/**
 * What a region's document may hold, from its theme's declaration: only the
 * cards the region allows, at any depth, and at most `max` top-level blocks.
 * Prose is always allowed. DocumentValidator applies it when a region is
 * saved, and PublishRevision again when it's published, against the
 * declaration as it is then.
 */
final readonly class RegionRules
{
    /**
     * @param  list<string>  $cards
     * @param  bool  $publishing  says a refusal comes from a declaration narrowed since the save
     */
    public function __construct(
        public array $cards,
        public int $max,
        public bool $publishing = false,
    ) {}

    /**
     * The rules for this item, or null for any item that isn't a region. A
     * region's theme is found by its slug, "{theme}--{region}", active or
     * not; a theme code may itself contain "--", so each installed theme is
     * tried. A region no theme declares any more is refused, never left
     * without limits.
     *
     * @throws InvalidDocumentException
     */
    public static function forContent(Content $content, bool $publishing = false): ?self
    {
        if ($content->type !== CoreTypes::REGION) {
            return null;
        }

        $slug = (string) $content->slug;
        $declarations = app(RegionDeclarations::class);

        foreach (app(ThemeRegistry::class)->all() as $theme) {
            $prefix = $theme->code.'--';

            if (! str_starts_with($slug, $prefix)) {
                continue;
            }

            $declared = $declarations->for($theme)[substr($slug, strlen($prefix))] ?? null;

            if ($declared !== null) {
                return new self($declared['cards'], $declared['max'], $publishing);
            }
        }

        throw InvalidDocumentException::at('', 'This region is no longer declared by its theme.');
    }

    /**
     * Returns when the document keeps to the region; throws at the first
     * block past the limit, or the first card it doesn't allow.
     *
     * @param  array<mixed>  $document
     *
     * @throws InvalidDocumentException
     */
    public function check(array $document): void
    {
        $blocks = is_array($document['content'] ?? null) ? $document['content'] : [];

        if (count($blocks) > $this->max) {
            throw InvalidDocumentException::at("content.{$this->max}", $this->publishing
                ? "This region now allows at most {$this->max} blocks."
                : "This region takes at most {$this->max} blocks.");
        }

        $this->cardsIn($blocks, 'content', app(CardRegistry::class));
    }

    /**
     * @param  array<mixed>  $nodes
     */
    private function cardsIn(array $nodes, string $path, CardRegistry $cards): void
    {
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');

            if ($cards->find($type) !== null && ! in_array($type, $this->cards, true)) {
                throw InvalidDocumentException::at("{$path}.{$index}", $this->publishing
                    ? "This region now doesn't allow the \"{$type}\" card."
                    : "The card \"{$type}\" can't be used in this region.");
            }

            if (is_array($node['content'] ?? null)) {
                $this->cardsIn($node['content'], "{$path}.{$index}.content", $cards);
            }
        }
    }
}
