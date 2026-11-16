<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

use App\Domain\Import\Convert\BlockContext;
use App\Domain\Import\Convert\Html\HtmlBlocks;
use App\Domain\Import\Convert\Html\HtmlFragment;
use Dom\Element;

/**
 * Does a piece of HTML's shortcodes as I-336 says, before it is read: a
 * row's cards held in place, what an active plugin drew kept (I-1704), a
 * dead wrapper's content kept and a dead tag removed, each said by name; a
 * bracket that isn't a shortcode stays text. One per conversion.
 */
final class ShortcodePass
{
    /** Page builders' and themes' families: a bracket of one of these is a shortcode (I-336). */
    public const array FAMILIES = ['vc_', 'td_', 'tdb_', 'tdm_', 'tdc_', 'et_pb_', 'fusion_'];

    /** How many reads of a row's own content may sit one inside another; deeper, the brackets are text. */
    private const int DEPTH = 64;

    /** @var array<int, true> what the item's drawn output has given out, by its place */
    private array $used = [];

    /** How many expansions are running, one inside another. */
    private int $depth = 0;

    public function __construct(private readonly ShortcodeMappings $mappings, private readonly BlockContext $context) {}

    /**
     * The HTML with each of its shortcodes done.
     */
    public function expand(string $html): string
    {
        if (! str_contains($html, '[') || $this->depth >= self::DEPTH) {
            return $html;
        }

        $this->depth++;

        try {
            return $this->render(ShortcodeParser::tree(ShortcodeParser::pieces($html)));
        } finally {
            $this->depth--;
        }
    }

    /**
     * @param  list<string|ShortcodeTag|ShortcodeNode>  $nodes
     */
    private function render(array $nodes): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $html .= match (true) {
                is_string($node) => $node,
                $node instanceof ShortcodeTag => $this->known($node->name) ? '' : $node->raw,
                default => $this->shortcode($node),
            };
        }

        return $html;
    }

    private function shortcode(ShortcodeNode $node): string
    {
        $name = $node->open->name;
        $named = array_filter(array_keys(ShortcodeParser::attributes($node->open->attributes)), is_string(...)) !== [];

        if (! $node->closed() && ! $named && ! $this->known($name)) {
            return $node->open->raw;
        }

        $row = $this->mappings->find($name);

        if ($row !== null) {
            return $this->context->hold($row->convert(Shortcode::of($node), $this->context));
        }

        if ($this->registered($name)) {
            $drawn = $this->drawn($node->open->raw);

            if ($drawn !== null) {
                return $this->output($name, $drawn);
            }

            $this->context->report->count('shortcode.unrendered', $name);

            return $this->render($node->children);
        }

        $this->context->report->count($node->closed() ? 'shortcode.unwrapped' : 'shortcode.removed', $name);

        return $this->render($node->children);
    }

    /**
     * Whether a name is a shortcode however it is written: registered on
     * the old site, mapped by a row, or of a known family.
     */
    private function known(string $name): bool
    {
        foreach (self::FAMILIES as $family) {
            if (str_starts_with($name, $family)) {
                return true;
            }
        }

        return $this->registered($name) || $this->mappings->find($name) !== null;
    }

    /**
     * Whether the old site registered this name at export, or the item
     * carries what it drew.
     */
    private function registered(string $name): bool
    {
        if (in_array($name, $this->context->site->shortcodes, true)) {
            return true;
        }

        foreach ($this->context->item->shortcodes as $entry) {
            if (preg_match('~^\[([A-Za-z0-9_][A-Za-z0-9_-]*)~', ltrim($entry['open']), $found) === 1 && $found[1] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the plugin drew for the next shortcode with this opening tag,
     * once each, in order; null when nothing is left for it.
     */
    private function drawn(string $open): ?string
    {
        $plain = fn (string $tag): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($tag, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        foreach ($this->context->item->shortcodes as $index => $entry) {
            if (! isset($this->used[$index]) && $plain($entry['open']) === $plain($open)) {
                $this->used[$index] = true;

                return $entry['html'];
            }
        }

        return null;
    }

    /**
     * What the plugin drew, kept (I-2390): words and inline marks in their
     * line, as the HTML its parsed tree gives back, so an unclosed tag in it
     * never runs on into the article; anything else as an Imported content
     * card; nothing for nothing.
     */
    private function output(string $name, string $html): string
    {
        $body = HtmlFragment::body($html);

        if (HtmlFragment::text($body) === '' && $body->querySelector('img, table, iframe, video, audio') === null) {
            return '';
        }

        if ($this->inline($body)) {
            $this->context->report->count('shortcode.inline', $name);

            return $body->innerHTML;
        }

        return $this->context->hold($this->context->imported($html, 'shortcode', $name));
    }

    /**
     * Whether the output holds only text and elements that sit inside a
     * line, with no picture, frame, player, object, drawing or form control.
     */
    private function inline(Element $body): bool
    {
        if ($body->querySelector('img, iframe, video, audio, object, embed, svg, form, input, button, select, textarea') !== null) {
            return false;
        }

        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child instanceof Element && (! in_array($child->localName, HtmlBlocks::INLINE, true) || $child->querySelector(HtmlBlocks::BLOCKS) !== null)) {
                return false;
            }
        }

        return true;
    }
}
