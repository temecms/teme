<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/**
 * Turns a release's markdown notes into an outline of plain text, never HTML (I-3329): the page
 * draws headings, paragraphs and items, so whatever GitHub returns cannot carry markup.
 */
final class ReleaseNotes
{
    /**
     * The notes as sections: `##` and `###` lines start one, `-` and `*` lines are its items, any other line joins its paragraph.
     *
     * @return list<array{heading: ?string, paragraphs: list<string>, items: list<string>}>
     */
    public static function outline(string $markdown): array
    {
        $sections = [];
        $heading = null;
        $paragraphs = [];
        $items = [];
        $lines = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $raw) {
            $line = trim($raw);

            if ($line === '') {
                self::endParagraph($lines, $paragraphs);

                continue;
            }

            if (preg_match('/^#{2,3}\s+(.*)$/', $line, $found) === 1) {
                self::endParagraph($lines, $paragraphs);

                if ($heading !== null || $paragraphs !== [] || $items !== []) {
                    $sections[] = ['heading' => $heading, 'paragraphs' => $paragraphs, 'items' => $items];
                }

                $heading = self::plain($found[1]);
                $paragraphs = [];
                $items = [];

                continue;
            }

            if (preg_match('/^[-*]\s+(.*)$/', $line, $found) === 1) {
                self::endParagraph($lines, $paragraphs);
                $items[] = self::plain($found[1]);

                continue;
            }

            $lines[] = self::plain($line);
        }

        self::endParagraph($lines, $paragraphs);

        if ($heading !== null || $paragraphs !== [] || $items !== []) {
            $sections[] = ['heading' => $heading, 'paragraphs' => $paragraphs, 'items' => $items];
        }

        return $sections;
    }

    /**
     * Closes the paragraph being read, if any.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $paragraphs
     */
    private static function endParagraph(array &$lines, array &$paragraphs): void
    {
        if ($lines !== []) {
            $paragraphs[] = implode(' ', $lines);
            $lines = [];
        }
    }

    /** The text with bold, italic marks and backticks removed and links reduced to their text. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);

        return trim(str_replace(['**', '__', '`'], '', $text));
    }
}
