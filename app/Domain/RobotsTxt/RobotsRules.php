<?php

/**
 * The robots.txt lines an administrator may add (I-866): User-agent, Allow,
 * Disallow and Sitemap rules, `#` notes and blank lines, nothing else. Each
 * line is checked on its own, numbered as the person sees it, and a line
 * that fails never reaches the file.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\RobotsTxt;

final class RobotsRules
{
    /** A rule: its field in any case, as RFC 9309 reads it, a colon, and its value. */
    private const string RULE = '/^(user-agent|allow|disallow|sitemap)\s*:\s*(.*)$/i';

    /**
     * What is wrong with one line, numbered from 1, or null when it is a
     * rule, a note or blank.
     */
    public static function problem(string $line, int $number): ?string
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        if (preg_match(self::RULE, $line, $match) !== 1) {
            return "Line {$number} isn’t a robots.txt rule. Start each line with User-agent:, Allow:, Disallow: or Sitemap:, or with # for a note.";
        }

        $field = ucfirst(strtolower($match[1]));
        $value = trim($match[2]);

        return match (true) {
            in_array($field, ['Allow', 'Disallow'], true) && $value !== '' && ! in_array($value[0], ['/', '*'], true) => "Line {$number}: start the path after {$field}: with /.",
            $field === 'Sitemap' && preg_match('~^https?://\S+$~i', $value) !== 1 => "Line {$number}: a Sitemap line needs a full address, starting https://.",
            $field === 'User-agent' && $value === '' => "Line {$number}: name the crawler after User-agent:, or write * for all of them.",
            default => null,
        };
    }

    /**
     * The first line's problem, or null when every line passes.
     */
    public static function firstProblem(string $text): ?string
    {
        foreach (self::lines($text) as $index => $line) {
            $problem = self::problem($line, $index + 1);

            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * The lines that pass, each trimmed, blank lines between them kept and
     * those at either end dropped.
     *
     * @return list<string>
     */
    public static function kept(string $text): array
    {
        $kept = [];

        foreach (self::lines($text) as $index => $line) {
            if (self::problem($line, $index + 1) === null) {
                $kept[] = trim($line);
            }
        }

        while ($kept !== [] && $kept[0] === '') {
            array_shift($kept);
        }

        while ($kept !== [] && $kept[array_key_last($kept)] === '') {
            array_pop($kept);
        }

        return $kept;
    }

    /**
     * The text's lines: a browser sends `\r\n` between a textarea's lines,
     * which is one break.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return preg_split('/\r\n|\r|\n/', $text) ?: [];
    }
}
