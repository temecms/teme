<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Fragment;
use App\Domain\Site\Document\Placement;
use App\Domain\Site\Document\PlacementContributor;
use App\Domain\Site\Document\Precedence;
use Teme\Contracts\Insertions\PagePosition;

/**
 * Plugins' page insertions in core's three placements (I-228), at the
 * plugin's precedence: the head's cleaned by HeadCleaner, the body's start
 * and end by InsertionCleaner, each from the markup the plugin gave, never
 * read back from the page; and, at the end of the body, what plugins fix to
 * the screen's edges, drawn by core.
 */
final readonly class PluginPlacements implements PlacementContributor
{
    public function __construct(
        private PageInsertions $insertions,
        private FixedInsertions $fixed,
    ) {}

    public function precedence(): Precedence
    {
        return Precedence::Plugin;
    }

    public function fragments(Placement $placement, DocumentPage $page): array
    {
        $kind = match ($placement) {
            Placement::Head => PagePosition::HEAD,
            Placement::BodyStart => PagePosition::BODY_START,
            Placement::BodyEnd => PagePosition::BODY_END,
        };
        $fragments = [];

        foreach ($this->insertions->at($kind) as $entry) {
            $html = $this->insertions->draw($entry, $placement === Placement::Head);

            if (trim($html) !== '') {
                $fragments[] = new Fragment($entry['insertion']->key, $html);
            }
        }

        if ($placement === Placement::BodyEnd) {
            $fixed = $this->fixed->all();

            if ($fixed !== []) {
                $fragments[] = new Fragment('teme.fixed-insertions', view('teme::components.fixed-insertions', ['fixed' => $fixed])->render());
            }
        }

        return $fragments;
    }
}
