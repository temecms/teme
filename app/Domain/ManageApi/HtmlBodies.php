<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

use App\Domain\Import\Convert\BlockConverter;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\KnownMedia;
use App\Domain\Import\Phases\ImportMessage;

/**
 * An item's body sent as HTML, converted to the Tiptap document Teme stores
 * (rule 7) by the import's converter, run with no import: one classic
 * chunk, an old site no address belongs to and no media known, so links
 * and images are kept as given (docs/proposals/management-api.md, P1).
 * CreateDraft's DocumentNormaliser then stores a link to this site by ID (rule 9).
 */
final readonly class HtmlBodies
{
    /** An address no link can be on, so no link is read as the old site's own. */
    private const string NO_SITE = 'https://management-api.invalid';

    public function __construct(private BlockConverter $converter) {}

    /**
     * The document the HTML becomes, and the converter's notes on what it
     * changed, such as an image on another site kept as a link.
     *
     * @return array{document: array<string, mixed>, notes: list<string>}
     */
    public function convert(string $html): array
    {
        $chunk = ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [$html], 'autop' => $html];
        $conversion = $this->converter->convert([$chunk], new ConversionSite(self::NO_SITE), new KnownMedia);

        return [
            'document' => $conversion->document,
            'notes' => array_map(fn (ImportMessage $message): string => $message->message, $conversion->messages),
        ];
    }
}
