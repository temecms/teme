<?php

/**
 * What a block mapping may use while one item converts (I-335): the old
 * site's addresses, the report, and the converter's readers for inner
 * blocks, HTML, inline content and images. Made afresh for each item.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert;

use App\Domain\Cards\Imported\MarkupCleaner;
use App\Domain\Cards\Types\ImportedContentCard;
use App\Domain\Import\Convert\Blocks\Embeds;
use App\Domain\Import\Convert\Html\Frames;
use App\Domain\Import\Convert\Html\HtmlBlocks;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\InlineContent;
use App\Domain\Import\Convert\Html\Nodes;
use App\Domain\Import\Convert\Html\OembedProviders;
use App\Domain\Import\Convert\Links\SiteLinks;
use App\Domain\Import\Convert\Media\ImageTrace;
use App\Domain\Import\Convert\Media\MediaTargets;
use App\Domain\Import\Convert\Media\PlayerCards;
use App\Domain\Import\Convert\Shortcodes\ShortcodeMappings;
use App\Domain\Import\Convert\Shortcodes\ShortcodePass;
use App\Domain\Import\Phases\SourceRef;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use Illuminate\Support\Str;

final class BlockContext
{
    /** The longest alt an Image card keeps (ImageCard's rule). */
    private const int ALT = 1000;

    /** The longest caption an Image card keeps; a longer one becomes a paragraph. */
    private const int CAPTION = 2000;

    /** What an address on the old site becomes. */
    public readonly SiteLinks $links;

    /** Keys the held nodes of this conversion, so markup can't name one. */
    private readonly string $nonce;

    /** Does a piece of HTML's shortcodes before it is read. */
    private readonly ShortcodePass $pass;

    /** @var list<list<array<string, mixed>>> */
    private array $held = [];

    public function __construct(
        private readonly BlockMappings $mappings,
        private readonly HtmlBlocks $html,
        private readonly InlineContent $inline,
        public readonly ConversionSite $site,
        private readonly MediaTargets $media,
        public readonly ConversionReport $report,
        public readonly SourceTargets $targets,
        public readonly ConversionItem $item,
        private readonly MarkupCleaner $cleaner,
        ShortcodeMappings $shortcodes,
    ) {
        $this->links = new SiteLinks($site, $media, $targets, $report);
        $this->nonce = bin2hex(random_bytes(6));
        $this->pass = new ShortcodePass($shortcodes, $this);
    }

    /**
     * The nodes these blocks make, in order.
     *
     * @param  list<ParsedBlock>  $blocks
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(array $blocks): array
    {
        $nodes = [];

        foreach ($blocks as $block) {
            array_push($nodes, ...$this->block($block));
        }

        return $nodes;
    }

    /**
     * The nodes a block's inner blocks make, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function children(ParsedBlock $block): array
    {
        return $this->blocks($block->innerBlocks);
    }

    /**
     * The blocks a piece of HTML makes.
     *
     * @return list<array<string, mixed>>
     */
    public function html(string $html): array
    {
        return $this->read($this->fragment($html));
    }

    /**
     * The blocks an element's children make.
     *
     * @return list<array<string, mixed>>
     */
    public function read(Element $element): array
    {
        return $this->html->read($element, $this);
    }

    /**
     * A piece of HTML read by the native parser, every element an article
     * never keeps taken out and counted.
     */
    public function fragment(string $html, bool $classic = false): Element
    {
        $body = HtmlFragment::body($this->pass->expand($html));
        $this->prepare($body, $classic);
        HtmlFragment::prune($body, $this->report);

        return $body;
    }

    /**
     * Holds the cards a piece of HTML makes before it is pruned: known
     * players' frames, and video and audio elements; what is inside an
     * element the article never keeps stays there.
     */
    private function prepare(Element $body, bool $classic): void
    {
        $gone = implode(', ', [...HtmlFragment::REMOVED, ...HtmlFragment::SILENT]);

        foreach (iterator_to_array($body->querySelectorAll('iframe, video, audio')) as $element) {
            if ($element->parentElement?->closest($gone) !== null) {
                continue;
            }

            $nodes = $element->localName === 'iframe' ? Frames::embed($element, $this) : PlayerCards::element($element, $this);

            if ($nodes !== null) {
                $this->holdNode($element, $nodes);
            }
        }

        foreach (iterator_to_array($body->querySelectorAll('p')) as $paragraph) {
            $this->ownLines($paragraph, $classic);
        }
    }

    /**
     * Holds an Embed card for an address alone on a line of a classic
     * chunk's paragraph, or alone in any paragraph, when WordPress's provider
     * list knows it (I-336); an address inside a link is never one.
     */
    private function ownLines(Element $paragraph, bool $classic): void
    {
        $lines = [[]];

        foreach (iterator_to_array($paragraph->childNodes) as $child) {
            if ($child instanceof Element && $child->localName === 'br') {
                $lines[] = [];

                continue;
            }

            $lines[array_key_last($lines)][] = $child;
        }

        if (! $classic && count($lines) > 1) {
            return;
        }

        foreach ($lines as $line) {
            $filled = array_values(array_filter($line, fn (Node $node): bool => ! ($node instanceof Text && trim($node->data) === '')));
            $only = count($filled) === 1 && $filled[0] instanceof Text ? $filled[0] : null;
            $url = $only === null ? '' : trim($only->data);

            if ($only !== null && preg_match('~^https?://[^\s<>"]+$~i', $url) === 1 && OembedProviders::matches($url)) {
                $this->holdNode($only, Embeds::card($this, $url));
            }
        }
    }

    /**
     * The first element of a piece of HTML matching the selector, or null.
     */
    public function element(string $html, string $selector): ?Element
    {
        return $this->fragment($html)->querySelector($selector);
    }

    /**
     * Holds these nodes for the place this HTML goes, and gives the element
     * that stands there: what a shortcode makes, put into the HTML it stood
     * in. Nothing for no nodes.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    public function hold(array $nodes): string
    {
        if ($nodes === []) {
            return '';
        }

        $this->held[] = $nodes;

        return '<'.Nodes::HELD.' data-held="'.$this->nonce.'-'.array_key_last($this->held).'"></'.Nodes::HELD.'>';
    }

    /**
     * Holds these nodes in the node's place: what a frame or a player
     * makes, in the parsed tree.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    public function holdNode(Node $node, array $nodes): void
    {
        $document = $node->ownerDocument;

        if ($document === null || $node->parentNode === null) {
            return;
        }

        $holder = $document->createElement(Nodes::HELD);
        $this->held[] = $nodes;
        $holder->setAttribute('data-held', $this->nonce.'-'.array_key_last($this->held));
        $node->parentNode->replaceChild($holder, $node);
    }

    /**
     * The nodes held for this element, once: nothing for an element this
     * conversion didn't make, or one already read.
     *
     * @return list<array<string, mixed>>
     */
    public function held(Element $holder): array
    {
        $key = (string) $holder->getAttribute('data-held');
        $index = str_starts_with($key, $this->nonce.'-') ? filter_var(substr($key, strlen($this->nonce) + 1), FILTER_VALIDATE_INT) : false;

        if (! is_int($index) || ! isset($this->held[$index])) {
            return [];
        }

        $nodes = $this->held[$index];
        $this->held[$index] = [];

        return $nodes;
    }

    /**
     * A paragraph or heading of the element's inline content, then an Image
     * card for each image it held; no textblock when it has no words. A
     * held element inside it splits it where it stood.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @return list<array<string, mixed>>
     */
    public function textblock(string $type, Element $element, array $attrs = []): array
    {
        $source = $element->cloneNode(true);

        if (! $source instanceof Element || $source->querySelector(Nodes::HELD) === null) {
            return $this->line($type, $element, $attrs);
        }

        $nodes = [];
        $part = $source->cloneNode(false);

        foreach (iterator_to_array($source->childNodes) as $child) {
            if ($child instanceof Element && $child->localName === Nodes::HELD && $part instanceof Element) {
                array_push($nodes, ...$this->line($type, $part, $attrs), ...$this->held($child));
                $part = $source->cloneNode(false);
                // A heading split in two gets a second ID (I-202).
                $attrs = isset($attrs['id']) ? [...$attrs, 'id' => $this->newId()] : $attrs;

                continue;
            }

            $part->appendChild($child);
        }

        return $part instanceof Element ? [...$nodes, ...$this->line($type, $part, $attrs)] : $nodes;
    }

    /**
     * One textblock of the element's inline content, then an Image card for
     * each image and the held nodes of each held element it held deeper
     * inside; no textblock when it has no words.
     *
     * @param  array<string, mixed>  $attrs
     *
     * @return list<array<string, mixed>>
     */
    private function line(string $type, Element $element, array $attrs): array
    {
        $read = $this->inline->read($element, $this->report, $this->links);
        $nodes = $read['nodes'] === [] ? [] : [Nodes::make($type, $attrs, $read['nodes'])];

        foreach ($read['images'] as $image) {
            array_push($nodes, ...$this->imageCard($image['element'], link: $image['link']));
        }

        foreach ($read['held'] as $holder) {
            array_push($nodes, ...$this->held($holder));
        }

        return $nodes;
    }

    /**
     * An Image card for the image when Teme imported its file, followed by
     * a paragraph for a caption too long for the card; nothing, and a
     * report, when Teme doesn't have it.
     *
     * @return list<array<string, mixed>>
     */
    public function imageCard(
        Element $img,
        ?int $attachmentId = null,
        ?string $caption = null,
        ?string $link = null,
        string $width = 'column',
        bool $decorative = false,
        ?string $linkDestination = null,
        ?int $captionId = null,
    ): array {
        $found = ImageTrace::find($img, $this->site, $this->media, $attachmentId, $captionId);

        if ($found['mediaId'] === null) {
            $found['mediaId'] = $this->targets->outsideImage(ImageTrace::source($img));
        }

        if ($found['mediaId'] === null) {
            if ($found['own'] && $this->targets->keepsOldSiteMedia()) {
                $this->report->count('image.outside', '', $this->site->hostOf(ImageTrace::source($img)));

                return $this->outsideLink($img);
            }

            if ($found['own']) {
                $this->report->count('image.missing', '', $found['file']);

                return [];
            }

            $this->report->count('image.outside', '', $found['host']);

            return $this->outsideLink($img);
        }

        $imageHost = $this->site->hostOf(ImageTrace::source($img));

        if ($imageHost !== null && $this->site->hostKind($imageHost) === 'other') {
            $this->report->count('images.other-host', $imageHost);
        }

        if ($link !== null && trim($link) !== '' && ! $this->linksToItself($link, $img, $linkDestination)) {
            $this->report->count('image.link');
        }

        $alt = $decorative ? '' : mb_substr(HtmlFragment::words((string) $img->getAttribute('alt')), 0, self::ALT);
        $long = $caption !== null && mb_strlen($caption) > self::CAPTION;

        $card = ['type' => 'image', 'attrs' => [
            'id' => $this->newId(),
            'mediaId' => $found['mediaId'],
            'alt' => $alt === '' ? null : $alt,
            'decorative' => $decorative,
            'caption' => $long ? null : $caption,
            'credit' => null,
            'width' => $width,
        ]];

        return $long ? [$card, Nodes::paragraph([['type' => 'text', 'text' => (string) $caption]])] : [$card];
    }

    /**
     * What the trace finds of a file by its attachment ID and address;
     * nothing is said, the caller says it.
     *
     * @return array{mediaId: string|null, own: bool, file: string, host: string|null}
     */
    public function file(?int $attachmentId, string $address): array
    {
        return ImageTrace::trace($address, $attachmentId === null ? [] : [$attachmentId], $this->site, $this->media);
    }

    /**
     * A caption's words, its links counted as left out; null when it has
     * none.
     */
    public function captionOf(Element $caption): ?string
    {
        foreach (iterator_to_array($caption->querySelectorAll('a[href]')) as $link) {
            $this->report->count('caption.links');
        }

        $words = HtmlFragment::text($caption);

        return $words === '' ? null : $words;
    }

    /**
     * A new node ID, as the editor makes one (I-202).
     */
    public function newId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * A marker where a WordPress object's content goes once the second pass
     * finds it (I-333); DocumentFit turns it into a PendingReference.
     *
     * @return list<array<string, mixed>>
     */
    public function reference(SourceRef $source): array
    {
        return [['type' => Nodes::REFERENCE, 'attrs' => ['type' => $source->type, 'id' => $source->id]]];
    }

    /**
     * Imported content cards (I-329) for a piece of HTML that doesn't
     * convert: cleaned by the card's own cleaner, its links and pictures
     * resolved as the article's are, and what it holds that makes cards of
     * Teme's own put in their places between them. A piece too large for a
     * card becomes Teme's own blocks.
     *
     * @return list<array<string, mixed>>
     */
    public function imported(string $html, string $sourceKind, ?string $sourceName = null): array
    {
        $body = HtmlFragment::body($sourceKind === 'shortcode' ? $html : $this->pass->expand($html));
        $this->prepare($body, false);
        $this->settleImages($body);
        $nodes = [];
        $run = [];
        $kept = false;

        foreach ([...iterator_to_array($body->childNodes), null] as $child) {
            if ($child !== null && ! ($child instanceof Element && $child->localName === Nodes::HELD)) {
                $run[] = $child;

                continue;
            }

            array_push($nodes, ...$this->card($run, $sourceKind, $sourceName, $kept));
            $run = [];

            if ($child instanceof Element) {
                array_push($nodes, ...$this->held($child));
            }
        }

        return $nodes;
    }

    /**
     * One card of a run of the piece's nodes, and the held nodes it held
     * deeper inside after it; one message per piece that made a card.
     *
     * @param  list<Node>  $run
     *
     * @return list<array<string, mixed>>
     */
    private function card(array $run, string $sourceKind, ?string $sourceName, bool &$kept): array
    {
        $document = $run === [] ? null : $run[0]->ownerDocument;

        if ($document === null) {
            return [];
        }

        $box = $document->createElement('div');
        $after = [];
        $shortcode = $sourceKind === 'shortcode';
        $name = $shortcode ? "[{$sourceName}]" : ($sourceName ?? 'core/html');

        foreach ($run as $node) {
            $box->appendChild($node);
        }

        foreach (iterator_to_array($box->querySelectorAll(Nodes::HELD)) as $holder) {
            array_push($after, ...$this->held($holder));
            $holder->remove();
        }

        // Its size first, with no address resolved or picture traced, so nothing is said twice.
        $probe = $this->cleaner->clean($box->innerHTML, picture: fn (string $src, string $class): string => str_repeat('0', 26));

        if ($probe->parts !== [] && ! $probe->fits()) {
            $this->report->count('imported.too-large', $name);
            HtmlFragment::prune($box, $this->report);

            return [...$this->read($box), ...$after];
        }

        $cleaned = $this->cleaner->clean(
            $box->innerHTML,
            fn (string $address): ?string => $this->links->href($address),
            fn (string $src, string $class): ?string => $this->picture($src, $class),
        );

        if ($cleaned->parts !== [] && ! $kept) {
            $this->report->count($shortcode ? 'shortcode.imported' : 'block.imported', $shortcode ? (string) $sourceName : $name);
            $kept = true;
        }

        $this->said($cleaned->removed);

        return $cleaned->parts === [] ? $after : [ImportedContentCard::node($cleaned, $sourceKind, $sourceName), ...$after];
    }

    /**
     * The Image card of an attachment by its ID; nothing, and a report,
     * when Teme doesn't have it.
     *
     * @return list<array<string, mixed>>
     */
    public function attachmentImage(int $id): array
    {
        $document = HtmlFragment::body('<img class="wp-image-'.$id.'">');
        $img = $document->querySelector('img');

        return $img === null ? [] : $this->imageCard($img, attachmentId: $id);
    }

    /**
     * A block whose content Teme can't convert: kept as an Imported content
     * card of its whole HTML, its inner blocks in their places (I-329).
     *
     * @return list<array<string, mixed>>
     */
    public function unconverted(ParsedBlock $block): array
    {
        return $this->imported($block->fullHtml(), 'block', (string) $block->name);
    }

    /**
     * Every image in the piece given its real address; one from another site
     * that the choices step didn't bring in becomes a link to it, and a link
     * holding only images Teme lacks is unwrapped, so its address isn't
     * resolved and said beside the image's own message (I-1703).
     */
    private function settleImages(Element $body): void
    {
        $document = $body->ownerDocument;

        foreach (iterator_to_array($body->querySelectorAll('img')) as $img) {
            $src = ImageTrace::source($img);

            if ($src === '' || $document === null) {
                continue;
            }

            $img->setAttribute('src', $src);
            $found = ImageTrace::find($img, $this->site, $this->media);

            if ($found['mediaId'] !== null || $this->targets->outsideImage($src) !== null) {
                continue;
            }

            if ($found['own'] && ! $this->targets->keepsOldSiteMedia()) {
                continue;
            }

            $this->report->count('image.outside', '', $found['host'] ?? $this->site->hostOf($src));
            $words = HtmlFragment::words((string) $img->getAttribute('alt'));
            $words = $words !== '' ? $words : (basename(rawurldecode((string) parse_url($src, PHP_URL_PATH))) ?: $src);

            if ($img->closest('a') !== null) {
                $img->replaceWith($words);

                continue;
            }

            $link = $document->createElement('a');
            $link->setAttribute('href', $src);
            $link->appendChild($document->createTextNode($words));
            $img->replaceWith($link);
        }

        foreach (iterator_to_array($body->querySelectorAll('a')) as $anchor) {
            $images = iterator_to_array($anchor->querySelectorAll('img'));

            if ($images === [] || HtmlFragment::text($anchor) !== '') {
                continue;
            }

            $lost = array_filter($images, function (Element $img): bool {
                $found = ImageTrace::find($img, $this->site, $this->media);

                return $found['mediaId'] === null
                    && $found['own']
                    && ! $this->targets->keepsOldSiteMedia()
                    && $this->targets->outsideImage(ImageTrace::source($img)) === null;
            });

            if (count($lost) === count($images)) {
                $anchor->replaceWith(...iterator_to_array($anchor->childNodes));
            }
        }
    }

    /**
     * The media ID for a picture in an Imported content card: its file in
     * the library, or an outside image the choices step brought in; an own
     * image Teme lacks is said, and null.
     */
    private function picture(string $src, string $class): ?string
    {
        $id = preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $found) === 1 ? (int) $found[1] : null;
        $traced = ImageTrace::trace($src, $id === null ? [] : [$id], $this->site, $this->media);

        if ($traced['mediaId'] !== null) {
            return $traced['mediaId'];
        }

        $outside = $this->targets->outsideImage($src);

        if ($outside !== null) {
            return $outside;
        }

        if (! $traced['own']) {
            return null;
        }

        if ($this->targets->keepsOldSiteMedia()) {
            $this->report->count('image.outside', '', $this->site->hostOf($src));

            return null;
        }

        $this->report->count('image.missing', '', $traced['file']);

        return null;
    }

    /**
     * What the cleaner removed, said in the report's words (D20): the
     * elements by name, inline styles and event attributes; a link or picture
     * it couldn't keep was already said by the resolver or the trace.
     *
     * @param  array<string, int>  $removed
     */
    private function said(array $removed): void
    {
        foreach ($removed as $name => $count) {
            if ($name === 'style-attribute') {
                $this->report->count('imported.styles', '', null, $count);
            } elseif ($name === 'event-attribute') {
                $this->report->count('html.event-attributes', '', null, $count);
            } elseif ($name !== 'link' && $name !== 'image') {
                $this->report->count('imported.removed', $name, null, $count);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function block(ParsedBlock $block): array
    {
        if ($block->name === null) {
            $html = $block->autop ?? $block->innerHtml;

            return trim($html) === '' ? [] : $this->read($this->fragment($html, classic: true));
        }

        $mapping = $this->mappings->find($block->name);

        if ($mapping !== null) {
            return $mapping->convert($block, $this);
        }

        if ($block->hasOwnContent()) {
            return $this->unconverted($block);
        }

        if ($block->innerBlocks !== []) {
            $this->report->count('block.flattened', $block->name);

            return $this->children($block);
        }

        $this->report->count('block.dynamic', $block->name);

        return [];
    }

    /**
     * An image from another site the choices step didn't bring in, as a
     * paragraph linking to it: its alt text, else its file name, else its
     * address, as the words (I-1703).
     *
     * @return list<array<string, mixed>>
     */
    private function outsideLink(Element $img): array
    {
        $src = ImageTrace::source($img);

        if ($src === '') {
            return [];
        }

        $words = HtmlFragment::words((string) $img->getAttribute('alt'));
        $words = $words !== '' ? $words : basename(rawurldecode((string) parse_url($src, PHP_URL_PATH)));
        $words = $words !== '' ? $words : $src;
        $href = InlineContent::href($src);

        return [Nodes::paragraph([['type' => 'text', 'text' => $words, ...($href === null ? [] : ['marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]])]])];
    }

    /**
     * Whether an image's link goes to its own file, a size of it, or its
     * attachment page: nothing Teme needs a link for.
     */
    private function linksToItself(string $link, Element $img, ?string $destination): bool
    {
        if (in_array($destination, ['media', 'attachment'], true) || preg_match('#[?&]attachment_id=\d+|/attachment/#', $link) === 1) {
            return true;
        }

        $linked = $this->site->uploadsKey($link);
        $shown = $this->site->uploadsKey(ImageTrace::source($img));

        return $linked !== null && $shown !== null && ImageTrace::original($linked) === ImageTrace::original($shown);
    }
}
