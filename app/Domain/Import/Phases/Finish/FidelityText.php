<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Finish;

use App\Domain\Import\Convert\Html\HtmlFragment;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/**
 * The words a reader reads in a piece of HTML (I-337), the same way for
 * WordPress's rendered page and Teme's: what scripts, styles, players and
 * forms hold left out, a break at every block, and each word lower case,
 * its quotes straight and its edges free of punctuation.
 */
final class FidelityText
{
    /** Elements whose text no reader reads as the article's. */
    public const array SKIPPED = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'embed', 'audio', 'video', 'form', 'button', 'select', 'textarea'];

    /** Elements that end a run of text. */
    public const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'br', 'caption', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'td', 'th', 'tr', 'ul',
    ];

    /** Every kind of space, the no-break and thin ones among them. */
    private const string SPACES = '/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]+/u';

    /** Curled quotes and primes, straightened (wptexturize() curls WordPress's). */
    private const array QUOTES = ['‘' => "'", '’' => "'", '‚' => "'", '′' => "'", '“' => '"', '”' => '"', '„' => '"', '″' => '"'];

    /**
     * Each block's text, its spaces collapsed, empty ones left out.
     *
     * @return list<string>
     */
    public static function blocks(string $html): array
    {
        $blocks = [''];
        self::walk(HtmlFragment::body($html), $blocks);
        $texts = array_map(fn (string $block): string => trim((string) preg_replace(self::SPACES, ' ', $block)), $blocks);

        return array_values(array_filter($texts, fn (string $text): bool => $text !== ''));
    }

    /**
     * The words of a text, as the comparison counts them.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $words = [];

        foreach (preg_split(self::SPACES, $text) ?: [] as $word) {
            $word = (string) preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', mb_strtolower(strtr($word, self::QUOTES)));

            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * @param  list<string>  $blocks
     */
    private static function walk(Node $node, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $blocks[] = (string) array_pop($blocks).$child->data;

                continue;
            }

            if (! $child instanceof Element || in_array(strtolower($child->localName), self::SKIPPED, true)) {
                continue;
            }

            $block = in_array(strtolower($child->localName), self::BLOCKS, true);

            if ($block) {
                $blocks[] = '';
            }

            self::walk($child, $blocks);

            if ($block) {
                $blocks[] = '';
            }
        }
    }
}
