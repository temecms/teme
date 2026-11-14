<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Comments;

use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Comments\CommentDocument;
use App\Domain\Import\Convert\ConversionReport;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Html\HtmlFragment;
use App\Domain\Import\Convert\Html\InlineContent;
use App\Domain\Import\Phases\ImportMessage;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * A WordPress comment's stored text as I-267's restricted document: set
 * into paragraphs and line breaks as WordPress shows it, read by the
 * converter's HTML reader, and kept to paragraphs, quotes, line breaks,
 * bold, italic, code and links, a link to the old site's own item by its
 * ID. It writes nothing.
 */
final readonly class CommentWords
{
    /** Tags WordPress sets apart from a comment's paragraphs when it shows them. */
    private const string BLOCK_TAGS = 'address|article|aside|blockquote|dd|details|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|summary|table|tbody|td|tfoot|th|thead|tr|ul';

    /** Elements whose content is one paragraph of a comment. */
    private const array TEXTBLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'address', 'figcaption', 'summary', 'dt'];

    /** Elements holding inline content, as the converter reads them (app/Domain/Import/Convert/Html/HtmlBlocks.php). */
    private const array INLINE = [
        'a', 'abbr', 'acronym', 'b', 'bdi', 'bdo', 'big', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i',
        'img', 'ins', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time',
        'tt', 'u', 'var', 'wbr',
    ];

    /** What makes an inline element hold blocks instead. */
    private const string HOLDS_BLOCKS = 'p, h1, h2, h3, h4, h5, h6, ul, ol, blockquote, pre, hr, table, div, figure, dl, address';

    /** The marks a comment keeps besides its links (CommentDocument::MARKS). */
    private const array KEPT_MARKS = ['bold', 'italic', 'code'];

    /** A web address in the words, found as CommentText finds a reader's (WordPress's make_clickable). */
    private const string ADDRESS = '/(?<![\p{L}\p{N}@.\/])(?:(?i:https?:\/\/)|www\.)[^\s<>"]+/u';

    /** What an address never ends with: it is the sentence's. */
    private const string TRAILING = '.,;:!?\'"';

    public function __construct(private InlineContent $inline) {}

    /**
     * The comment's document, and the message saying what it left out, or
     * null when it kept everything.
     *
     * @return array{document: array<string, mixed>, message: ImportMessage|null}
     */
    public function read(string $content, ConversionSite $site): array
    {
        $report = new ConversionReport;
        $left = ['links' => 0, 'images' => 0, 'formatting' => false];
        $body = HtmlFragment::body(self::paragraphs($content));
        HtmlFragment::prune($body, $report);

        $blocks = $this->blocks($body, 0, $site, $report, $left);

        return [
            'document' => ['type' => 'doc', 'content' => $blocks === [] ? [['type' => 'paragraph']] : $blocks],
            'message' => self::message($report, $left),
        ];
    }

    /**
     * The text as WordPress shows a comment (I-267): a blank line ends a
     * paragraph, every other line break is a line break, block tags stand
     * apart, and a pre's lines stay lines.
     */
    private static function paragraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace_callback('#<pre\b[^>]*>.*?</pre>#is', fn (array $pre): string => str_replace("\n", '<br>', $pre[0]), $text);
        $text = (string) preg_replace('#</?(?:'.self::BLOCK_TAGS.')\b[^>]*>#i', "\n\n".'$0'."\n\n", $text);
        $html = '';

        foreach (preg_split('/\n[ \t]*\n/', $text) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $html .= preg_match('#^</?(?:'.self::BLOCK_TAGS.')\b[^>]*>$#i', $chunk) === 1
                ? $chunk
                : '<p>'.str_replace("\n", '<br>', $chunk).'</p>';
        }

        return $html;
    }

    /**
     * The blocks an element's children make: runs of inline content as
     * paragraphs, quotes as quotes to CommentDocument's depth, and every
     * other block's paragraphs in its place.
     *
     * @param  array{links: int, images: int, formatting: bool}  $left
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(Element $root, int $quotes, ConversionSite $site, ConversionReport $report, array &$left): array
    {
        $blocks = [];
        $run = [];

        foreach (iterator_to_array($root->childNodes) as $child) {
            if (self::isInline($child)) {
                $run[] = $child;

                continue;
            }

            array_push($blocks, ...$this->run($run, $site, $report, $left));
            $run = [];

            if ($child instanceof Element) {
                array_push($blocks, ...$this->block($child, $quotes, $site, $report, $left));
            }
        }

        array_push($blocks, ...$this->run($run, $site, $report, $left));

        return $blocks;
    }

    /**
     * @param  array{links: int, images: int, formatting: bool}  $left
     *
     * @return list<array<string, mixed>>
     */
    private function block(Element $element, int $quotes, ConversionSite $site, ConversionReport $report, array &$left): array
    {
        if ($element->localName === 'blockquote') {
            if ($quotes >= CommentDocument::MAX_QUOTE_DEPTH) {
                return $this->blocks($element, $quotes, $site, $report, $left);
            }

            $inner = $this->blocks($element, $quotes + 1, $site, $report, $left);

            return $inner === [] ? [] : [['type' => 'blockquote', 'content' => $inner]];
        }

        return in_array($element->localName, self::TEXTBLOCKS, true)
            ? $this->paragraph($element, $site, $report, $left)
            : $this->blocks($element, $quotes, $site, $report, $left);
    }

    private static function isInline(Node $node): bool
    {
        return $node instanceof Text
            || ($node instanceof Element && in_array($node->localName, self::INLINE, true) && $node->querySelector(self::HOLDS_BLOCKS) === null);
    }

    /**
     * A paragraph of a run of inline nodes; nothing when it holds no words.
     *
     * @param  list<Node>  $run
     * @param  array{links: int, images: int, formatting: bool}  $left
     *
     * @return list<array<string, mixed>>
     */
    private function run(array $run, ConversionSite $site, ConversionReport $report, array &$left): array
    {
        $document = $run[0]->ownerDocument ?? null;

        if ($document === null) {
            return [];
        }

        $paragraph = $document->createElement('p');

        foreach ($run as $node) {
            $paragraph->appendChild($node);
        }

        return $this->paragraph($paragraph, $site, $report, $left);
    }

    /**
     * One paragraph of an element's inline content, its images left out.
     *
     * @param  array{links: int, images: int, formatting: bool}  $left
     *
     * @return list<array<string, mixed>>
     */
    private function paragraph(Element $element, ConversionSite $site, ConversionReport $report, array &$left): array
    {
        $read = $this->inline->read($element, $report);
        $left['images'] += count($read['images']);
        $nodes = self::joined($this->kept($read['nodes'], $site, $left));

        return $nodes === [] ? [] : [['type' => 'paragraph', 'content' => $nodes]];
    }

    /**
     * Each node with the marks a comment keeps, its link resolved or left
     * out, and the web addresses in its plain words linked.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array{links: int, images: int, formatting: bool}  $left
     *
     * @return list<array<string, mixed>>
     */
    private function kept(array $nodes, ConversionSite $site, array &$left): array
    {
        $kept = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) !== 'text' || ! is_string($node['text'] ?? null)) {
                $kept[] = ['type' => 'hardBreak'];

                continue;
            }

            $marks = [];
            $linked = false;

            foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
                $type = is_array($mark) && is_string($mark['type'] ?? null) ? $mark['type'] : '';

                if (in_array($type, self::KEPT_MARKS, true)) {
                    $marks[] = ['type' => $type];
                } elseif ($type === 'link') {
                    $linked = true;
                    $href = self::href(is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['href'] ?? null) ? $mark['attrs']['href'] : '', $site);

                    if ($href === null) {
                        $left['links']++;
                    } else {
                        $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
                    }
                } else {
                    $left['formatting'] = true;
                }
            }

            // WordPress links no address inside a link or inside code.
            array_push($kept, ...($linked || in_array(['type' => 'code'], $marks, true) ? [self::text($node['text'], $marks)] : self::clickable($node['text'], $marks, $site)));
        }

        return $kept;
    }

    /**
     * Plain words with each web address in them linked, by the rule
     * CommentText links a reader's.
     *
     * @param  list<array<string, mixed>>  $marks
     *
     * @return list<array<string, mixed>>
     */
    private static function clickable(string $text, array $marks, ConversionSite $site): array
    {
        $nodes = [];
        $at = 0;

        preg_match_all(self::ADDRESS, $text, $found, PREG_OFFSET_CAPTURE);

        foreach ($found[0] as [$typed, $offset]) {
            $address = self::withoutTrailing($typed);
            $href = preg_match('/^(?:(?i:https?:\/\/)|www\.)\S/u', $address) === 1
                ? self::href(preg_match('/^https?:\/\//i', $address) === 1 ? $address : 'https://'.$address, $site)
                : null;

            if ($href === null) {
                continue;
            }

            if ($offset > $at) {
                $nodes[] = self::text(substr($text, $at, $offset - $at), $marks);
            }

            $nodes[] = self::text($address, [...$marks, ['type' => 'link', 'attrs' => ['href' => $href]]]);
            $at = $offset + strlen($address);
        }

        if ($at < strlen($text)) {
            $nodes[] = self::text(substr($text, $at), $marks);
        }

        return $nodes;
    }

    /**
     * The address without what ends the sentence: a trailing full stop,
     * comma, semicolon, colon, exclamation or question mark or quote, and a
     * closing bracket the address didn't open.
     */
    private static function withoutTrailing(string $address): string
    {
        while ($address !== '') {
            $last = substr($address, -1);

            if (str_contains(self::TRAILING, $last)
                || ($last === ')' && substr_count($address, ')') > substr_count($address, '('))
                || ($last === ']' && substr_count($address, ']') > substr_count($address, '['))) {
                $address = substr($address, 0, -1);

                continue;
            }

            break;
        }

        return $address;
    }

    /**
     * The address a link in a comment keeps, or null for none: another
     * site's web or email address as it is; the old site's own address as
     * the item Teme's path table has there, current or old, by its ID
     * (rule 9).
     */
    private static function href(string $href, ConversionSite $site): ?string
    {
        $absolute = $site->absolute($href);
        $scheme = $absolute === null ? '' : strtolower((string) parse_url($absolute, PHP_URL_SCHEME));

        if ($absolute === null || strlen($absolute) > 2048 || ! in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return null;
        }

        if ($scheme === 'mailto' || ! $site->isOwn($absolute)) {
            return $absolute;
        }

        // Later (I-1891): the converter's link resolver, for ?p= and an old address Teme answers elsewhere.
        $row = Paths::find(PublicRequestPath::normalise((string) parse_url($absolute, PHP_URL_PATH)));

        return $row !== null && $row->target_type === 'content' ? 'teme:content/'.$row->target_id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     *
     * @return array<string, mixed>
     */
    private static function text(string $text, array $marks): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /**
     * Neighbouring text with the same marks as one node, as Tiptap keeps it.
     *
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function joined(array $nodes): array
    {
        $joined = [];

        foreach ($nodes as $node) {
            $last = array_key_last($joined);

            if ($last !== null && $node['type'] === 'text' && $joined[$last]['type'] === 'text'
                && ($joined[$last]['marks'] ?? []) === ($node['marks'] ?? [])) {
                $joined[$last]['text'] .= $node['text'];

                continue;
            }

            $joined[] = $node;
        }

        return $joined;
    }

    /**
     * What the words left out, in one message, or null.
     *
     * @param  array{links: int, images: int, formatting: bool}  $left
     */
    private static function message(ConversionReport $report, array $left): ?ImportMessage
    {
        $links = $left['links'];
        $elements = [];

        foreach ($report->messages() as $message) {
            $count = is_int($message->context['count'] ?? null) ? $message->context['count'] : 0;

            if ($message->code === 'link.removed') {
                $links += $count;
            } elseif ($message->code === 'html.removed') {
                $elements[] = [is_string($message->context['element'] ?? null) ? $message->context['element'] : '', $count];
            }
        }

        $parts = [];

        if ($links > 0) {
            $parts[] = $links === 1 ? 'the address of 1 link' : "the addresses of {$links} links";
        }

        if ($left['images'] > 0) {
            $parts[] = $left['images'] === 1 ? '1 image' : "{$left['images']} images";
        }

        foreach ($elements as [$name, $count]) {
            $parts[] = $count === 1 ? "1 {$name} element" : "{$count} {$name} elements";
        }

        if ($left['formatting']) {
            $parts[] = 'some formatting';
        }

        if ($parts === []) {
            return null;
        }

        $last = array_pop($parts);
        $named = $parts === [] ? $last : implode(', ', $parts).' and '.$last;

        return new ImportMessage(
            'comment.words-changed',
            "Kept its words, but left out {$named}: a comment on Teme holds paragraphs, quotes, bold, italic, code and links.",
            ['links' => $links, 'images' => $left['images'], 'elements' => array_sum(array_column($elements, 1)), 'formatting' => $left['formatting']],
        );
    }
}
