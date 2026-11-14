<?php

declare(strict_types=1);

namespace App\Domain\Api\Llms;

use App\Domain\Api\DocumentLinks;
use App\Domain\Api\Shapes\MediaShape;
use App\Domain\Cards\CardMedia;
use App\Domain\Cards\CardRegistry;
use App\Domain\Cards\DocumentSchema;

/**
 * A stored document as Markdown, for the `.md` copy of a page (I-713):
 * headings, paragraphs, lists, task lists, quotes, code, rules and tables,
 * with bold, italic, strike, code and links. A link by ID is written at its
 * address now and by its text alone when its target has none; an image card
 * is an image at its widest size; any other card is its own words. Text that
 * would read as Markdown is escaped, and no HTML is ever written.
 */
final class MarkdownDocument
{
    /** The nodes that open a list. */
    private const array LISTS = ['bulletList', 'orderedList', 'taskList'];

    /**
     * @param  array<string, array<string, mixed>>  $media  each live file the document shows, by ID
     */
    private function __construct(
        private readonly DocumentLinks $links,
        private readonly CardRegistry $cards,
        private readonly array $media,
    ) {}

    /**
     * The document as Markdown: its blocks one blank line apart, with no
     * newline at the end; empty for a document with nothing to write.
     *
     * @param  array<mixed>  $document
     */
    public static function of(array $document): string
    {
        $writer = new self(app(DocumentLinks::class), app(CardRegistry::class), app(CardMedia::class)->forDocument($document));

        return implode("\n\n", $writer->blocks((array) ($document['content'] ?? [])));
    }

    /**
     * Words on one line as plain text in Markdown, for a title, an
     * excerpt or a name: each newline a space, anything that would read as
     * Markdown escaped.
     */
    public static function words(string $text): string
    {
        return self::escape(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Each node written as a block, those with nothing to write left out.
     *
     * @param  array<mixed>  $nodes
     *
     * @return list<string>
     */
    private function blocks(array $nodes): array
    {
        $blocks = [];

        foreach ($nodes as $node) {
            $block = is_array($node) ? $this->block($node) : '';

            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<mixed>  $node
     */
    private function block(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        $attrs = (array) ($node['attrs'] ?? []);
        $content = (array) ($node['content'] ?? []);

        return match ($type) {
            'paragraph' => self::escapeLineStarts($this->inline($content)),
            'heading' => $this->heading((int) ($attrs['level'] ?? 2), $content),
            'bulletList' => $this->list($content, fn (int $number, array $item): string => '- '),
            'orderedList' => $this->list($content, fn (int $number, array $item): string => ((int) ($attrs['start'] ?? 1) + $number).'. '),
            'taskList' => $this->list($content, fn (int $number, array $item): string => ((bool) (((array) ($item['attrs'] ?? []))['checked'] ?? false)) ? '- [x] ' : '- [ ] '),
            'blockquote' => $this->quote($content),
            'codeBlock' => $this->code((string) ($attrs['language'] ?? ''), $content),
            'horizontalRule' => '---',
            'table' => $this->table($content),
            'text', 'hardBreak' => '',
            default => $this->other($type, $attrs, $content),
        };
    }

    /**
     * A card by its kind, or any other node by what it holds.
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<mixed>  $content
     */
    private function other(string $type, array $attrs, array $content): string
    {
        $card = $this->cards->find($type);

        if ($card === null) {
            return implode("\n\n", $this->blocks($content));
        }

        if ($type === 'image') {
            return $this->image($attrs);
        }

        // As the page draws it: a button to an article with no address is nothing, label and all.
        $article = $attrs['contentId'] ?? null;

        if ($type === 'button' && is_string($article) && $article !== '' && $this->links->url($article) === null) {
            return '';
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $card->plainText($attrs)));

        return $text === '' ? '' : self::escapeLineStarts(self::escape($text));
    }

    /**
     * An image card as an image at its widest size; nothing for a file that
     * is gone or in the trash.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function image(array $attrs): string
    {
        $id = (string) ($attrs['mediaId'] ?? '');
        $file = $this->media[$id] ?? null;
        $url = $file === null ? null : MediaShape::of([...$file, 'id' => $id])['url'];

        if (! is_string($url) || $url === '') {
            return '';
        }

        $url = $this->links->href($url) ?? $url;
        $alt = trim((string) ($attrs['alt'] ?? ''));
        $alt = (bool) ($attrs['decorative'] ?? false) ? '' : ($alt !== '' ? $alt : trim((string) ($file['alt'] ?? '')));
        $alt = (string) preg_replace('/[\[\]\\\\]/', '\\\\$0', (string) preg_replace('/\s+/u', ' ', $alt));

        return '!['.$alt.']('.self::destination($url).')';
    }

    /**
     * @param  array<mixed>  $content
     */
    private function heading(int $level, array $content): string
    {
        $text = $this->inline($content);

        return $text === '' ? '' : str_repeat('#', max(1, min(6, $level))).' '.$text;
    }

    /**
     * A list's items, each opened by the marker $marker gives for its number
     * and its node.
     *
     * @param  array<mixed>  $items
     * @param  callable(int, array<mixed>): string  $marker
     */
    private function list(array $items, callable $marker): string
    {
        $written = [];

        foreach (array_values(array_filter($items, is_array(...))) as $number => $item) {
            $written[] = $this->item($marker($number, $item), (array) ($item['content'] ?? []));
        }

        return implode("\n", $written);
    }

    /**
     * One item: its first line after the marker, the rest under it. A list
     * inside it follows on the next line, any other block after a blank one.
     *
     * @param  array<mixed>  $children
     */
    private function item(string $marker, array $children): string
    {
        $body = '';

        foreach ($children as $child) {
            $block = is_array($child) ? $this->block($child) : '';

            if ($block === '') {
                continue;
            }

            $isList = in_array((string) ($child['type'] ?? ''), self::LISTS, true);
            $body .= ($body === '' ? '' : ($isList ? "\n" : "\n\n")).$block;
        }

        if ($body === '') {
            return rtrim($marker);
        }

        $pad = str_repeat(' ', strlen($marker));
        $lines = explode("\n", $body);

        foreach ($lines as $index => $line) {
            $lines[$index] = $index === 0 ? $marker.$line : ($line === '' ? '' : $pad.$line);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<mixed>  $content
     */
    private function quote(array $content): string
    {
        $body = implode("\n\n", $this->blocks($content));

        if ($body === '') {
            return '';
        }

        return implode("\n", array_map(fn (string $line): string => $line === '' ? '>' : '> '.$line, explode("\n", $body)));
    }

    /**
     * A code block, fenced by more backticks than any run inside it.
     *
     * @param  array<mixed>  $content
     */
    private function code(string $language, array $content): string
    {
        $code = '';

        foreach ($content as $node) {
            $code .= is_array($node) ? (string) ($node['text'] ?? '') : '';
        }

        if (trim($code) === '') {
            return '';
        }

        $fence = str_repeat('`', max(3, self::longestRun($code, '`') + 1));

        return $fence.(preg_match(DocumentSchema::LANGUAGE, $language) === 1 ? $language : '')."\n".rtrim($code, "\n")."\n".$fence;
    }

    /**
     * A table as pipes: its first row is the header, as the format needs one.
     *
     * @param  array<mixed>  $rows
     */
    private function table(array $rows): string
    {
        $lines = [];

        foreach (array_values(array_filter($rows, is_array(...))) as $number => $row) {
            $cells = [];
            $aligns = [];

            foreach ((array) ($row['content'] ?? []) as $cell) {
                if (! is_array($cell)) {
                    continue;
                }

                $text = (string) preg_replace('/\s+/u', ' ', implode(' ', $this->blocks((array) ($cell['content'] ?? []))));
                $cells[] = str_replace('|', '\\|', trim($text));
                $aligns[] = (string) (((array) ($cell['attrs'] ?? []))['align'] ?? '');
            }

            if ($cells === []) {
                continue;
            }

            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($number === 0) {
                $lines[] = '| '.implode(' | ', array_map(fn (string $align): string => match ($align) {
                    'left' => ':---',
                    'center' => ':---:',
                    'right' => '---:',
                    default => '---',
                }, $aligns)).' |';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Text nodes and line breaks as one run of Markdown.
     *
     * @param  array<mixed>  $nodes
     */
    private function inline(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $text .= match ((string) ($node['type'] ?? '')) {
                'text' => $this->run($node),
                'hardBreak' => "  \n",
                default => '',
            };
        }

        return trim($text);
    }

    /**
     * One text node with its marks: bold, italic, strike, code and a link
     * the target of which has an address; any other mark is dropped. The
     * spaces around the words stay outside the marks, where Markdown wants them.
     *
     * @param  array<mixed>  $node
     */
    private function run(array $node): string
    {
        $text = (string) preg_replace('/\R/u', ' ', (string) ($node['text'] ?? ''));
        $marks = [];

        foreach ((array) ($node['marks'] ?? []) as $mark) {
            if (is_array($mark)) {
                $marks[(string) ($mark['type'] ?? '')] = (array) ($mark['attrs'] ?? []);
            }
        }

        preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $parts);

        if (($parts[2] ?? '') === '') {
            return $text;
        }

        $words = isset($marks['code']) ? self::codeSpan($parts[2]) : self::escape($parts[2]);

        foreach (['strike' => '~~', 'italic' => '*', 'bold' => '**'] as $mark => $fence) {
            if (isset($marks[$mark])) {
                $words = $fence.$words.$fence;
            }
        }

        if (isset($marks['link'])) {
            $href = $this->links->href((string) ($marks['link']['href'] ?? ''));

            if ($href !== null) {
                $words = '['.$words.']('.self::destination($href).')';
            }
        }

        return $parts[1].$words.$parts[3];
    }

    /**
     * Words as plain text in Markdown: what would open emphasis, a link,
     * code or a tag is escaped with a backslash.
     */
    private static function escape(string $text): string
    {
        $text = (string) preg_replace('/[\\\\`*\[\]<>~]/', '\\\\$0', $text);

        // An underscore inside a word is just an underscore.
        return (string) preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\$0', $text);
    }

    /**
     * What would make a line a heading, a list item or a rule is escaped,
     * on each line of a paragraph.
     */
    private static function escapeLineStarts(string $text): string
    {
        return (string) preg_replace_callback('/^( {0,3})(?:(#{1,6})(?=\s|$)|([-+])(?=\s|$)|(\d{1,9})(?=[.)](?:\s|$))|(=+|-+)$)/m', fn (array $found): string => $found[1].match (true) {
            ($found[2] ?? '') !== '' => '\\'.$found[2],
            ($found[3] ?? '') !== '' => '\\'.$found[3],
            ($found[4] ?? '') !== '' => $found[4].'\\',
            default => '\\'.$found[5],
        }, $text);
    }

    /**
     * A code span: fenced by one more backtick than any run inside it, and
     * spaced from them when it starts or ends with one.
     */
    private static function codeSpan(string $code): string
    {
        $fence = str_repeat('`', self::longestRun($code, '`') + 1);
        $pad = str_starts_with($code, '`') || str_ends_with($code, '`') ? ' ' : '';

        return $fence.$pad.$code.$pad.$fence;
    }

    /**
     * An address as a link's destination: the characters that would end it
     * written as escapes.
     */
    private static function destination(string $address): string
    {
        return strtr($address, [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E']);
    }

    /**
     * The longest run of this character in the text.
     */
    private static function longestRun(string $text, string $character): int
    {
        preg_match_all('/'.preg_quote($character, '/').'+/', $text, $runs);

        return $runs[0] === [] ? 0 : max(array_map(strlen(...), $runs[0]));
    }
}
