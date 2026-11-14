<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Head\PageHead;
use App\Domain\Themes\ComposerRegistry;
use App\Domain\Themes\ViewIntent;

/**
 * A public page's `<title>` (I-247, I-290): what PageHead resolves for the
 * page, so the title the head's other entries and the structured data read
 * is the one the browser shows. Search and social's templates decide it.
 */
final readonly class DocumentTitle
{
    public function __construct(
        private PageHead $heads,
        private ComposerRegistry $composers,
    ) {}

    /**
     * The title for this view model, on this page's kind; $isFront says it
     * is the site's address, or one of its numbered pages.
     */
    public function for(?object $page, bool $isFront = false): string
    {
        $intent = $this->composers->intent();

        if ($intent !== null && $intent->isFront !== $isFront) {
            $intent = new ViewIntent($intent->kind, $intent->type, $intent->template, $intent->taxonomy, $intent->term, $intent->code, $isFront);
        }

        return $this->heads->for(new DocumentPage($intent, $page))->title;
    }
}
