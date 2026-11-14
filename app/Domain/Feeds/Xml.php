<?php

declare(strict_types=1);

namespace App\Domain\Feeds;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Everything a feed does to text on its way into XML: escaping, removing what
 * XML 1.0 cannot carry, wrapping HTML in CDATA, and dating in RFC 822. One
 * place, so no value reaches a feed by another road. It holds no state.
 */
final class Xml
{
    /**
     * Text for an element's content: `&`, `<` and `>` escaped, the rest as it
     * is, after clean().
     */
    public static function text(string $text): string
    {
        return htmlspecialchars(self::clean($text), ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Text for a double-quoted attribute: as text(), and the quotes escaped.
     */
    public static function attribute(string $text): string
    {
        return htmlspecialchars(self::clean($text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * The text with what XML 1.0 cannot carry removed: every control
     * character but tab, line feed and carriage return, the noncharacters
     * U+FFFE and U+FFFF, and unpaired surrogates. A byte that isn't UTF-8
     * becomes `?` first, since the pattern reads characters and would fail
     * the whole text on it. One such character makes a parser refuse the
     * whole feed.
     */
    public static function clean(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($text, 'UTF-8'));
    }

    /**
     * HTML as one CDATA section, or several: a `]]>` inside it ends a section
     * and starts the next after its `>`, so a parser gives back the bytes it
     * was given.
     */
    public static function cdata(string $html): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', self::clean($html)).']]>';
    }

    /**
     * A moment in RFC 822, as RSS dates are, in UTC: `Mon, 05 Oct 2026
     * 08:30:00 +0000`. PHP's date formats name days and months in English
     * whatever the application's language.
     */
    public static function date(DateTimeInterface $moment): string
    {
        return (new DateTimeImmutable('@'.$moment->getTimestamp()))->format(DATE_RSS);
    }
}
