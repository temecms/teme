<?php

/**
 * Slugs for content: made from a title, unique among live items of one type
 * in one language (I-3360). Trashed items do not hold their slug (I-142).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Fields\RequiredFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

final class Slugs
{
    /** Scripts whose vowel signs are combining marks, kept as written in a slug. */
    /**
     * Marks that can't be seen: the combining grapheme joiner and the
     * variation selectors, which would make a second address look like
     * the first.
     *
     * @since 1.3.3
     */
    public const string INVISIBLE_MARKS = '\x{034F}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}';

    /**
     * Teme's slug rule: letters and numbers of any script, lower case, each
     * letter with the vowel signs and other combining marks written with it
     * (none it can't show), joined by single hyphens. A slug is checked in
     * its composed form (normal()), the form a request is looked up by.
     *
     * @since 1.3.3
     */
    public const string RULE = '/^(?!.*['.self::INVISIBLE_MARKS.'])(?:[\p{Ll}\p{Lo}\p{N}][\p{Mn}\p{Mc}]*)+(?:-(?:[\p{Ll}\p{Lo}\p{N}][\p{Mn}\p{Mc}]*)+)*$/u';

    private const string KEPT_SCRIPTS = '\p{Devanagari}\p{Bengali}\p{Gurmukhi}\p{Gujarati}\p{Oriya}\p{Tamil}\p{Telugu}\p{Kannada}\p{Malayalam}\p{Sinhala}\p{Thai}\p{Lao}\p{Tibetan}\p{Myanmar}\p{Khmer}';

    /**
     * The slug some text makes, as Str::slug makes it (lower case, Latin
     * letters without their accents, words joined by single hyphens), but
     * with each letter of a script whose vowel signs are combining marks
     * kept as written, its marks with it; an empty slug when nothing of it
     * is usable.
     *
     * @since 1.3.3
     */
    public static function fromText(string $text): string
    {
        $text = self::normal($text);
        // Str::ascii would spell these scripts in Latin letters without their vowels, or drop them.
        $text = (string) preg_replace_callback('/[^'.self::KEPT_SCRIPTS.']+/u', fn (array $run): string => Str::ascii($run[0], 'en'), $text);
        $text = str_replace(['_', '@'], ['-', '-at-'], $text);
        $text = (string) preg_replace(['/['.self::INVISIBLE_MARKS.']/u', '/[^-\pL\p{Mn}\p{Mc}\pN\s]+/u'], '', mb_strtolower($text, 'UTF-8'));
        // A mark belongs to the letter before it; one with none goes.
        $text = (string) preg_replace(['/[-\s]+/u', '/(?<=^|-)[\p{Mn}\p{Mc}]+/u', '/-{2,}/'], ['-', '', '-'], $text);

        return self::normal(trim($text, '-'));
    }

    /**
     * The text in Unicode's composed form (NFC), as every request's address
     * is read: a slug stored in another form could never be opened.
     *
     * @since 1.3.3
     */
    public static function normal(string $text): string
    {
        $composed = Normalizer::normalize($text, Normalizer::FORM_C);

        return $composed === false ? $text : $composed;
    }

    /**
     * The slug a title would have before any de-duplication.
     */
    public static function fromTitle(string $title): string
    {
        $base = self::fromText($title) ?: 'untitled';

        // Leave room for a suffix inside the 255-character column. Titles may
        // be up to 512 characters, so without this a long title fails the
        // insert. WordPress and Craft both truncate at this point.
        return rtrim(mb_substr($base, 0, 200), '-');
    }

    /**
     * $base if no live item of this type in this language uses it,
     * otherwise $base with the next free numeric suffix. A null language is
     * the default.
     */
    public static function unique(string $type, string $base, ?string $language = null): string
    {
        // One query however many posts already share this title. Checking
        // "-2", "-3", "-4" in turn costs a query per collision, which does
        // not survive a million rows. The LIKE prefix is answered from the
        // live (type, slug) index because slug has the C collation.
        //
        // The plain slug is used whenever it is free. Only once it is taken
        // do we look for the highest numeric suffix — otherwise an existing
        // "covid-19" (from a title "Covid 19") would push a new "Covid" to
        // "covid-20". When both are taken the result can skip numbers, which
        // is harmless: it is still unique.
        $row = DB::table('contents')
            ->where('type', $type)
            ->whereNull('deleted_at')
            // One language's slugs: null is the default language (I-3360).
            ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->whereRaw('slug ~ ?', ['^'.preg_quote($base, '/').'(-[0-9]+)?$'])
            ->selectRaw(
                'max(case when slug = ? then 1 else 0 end) as base_taken, '
                // The position argument must be cast explicitly: an untyped bound
                // parameter here makes Postgres resolve substring(text, text) —
                // the regex-extraction overload — instead of substring(text, int),
                // and it silently returns null rather than erroring.
                .'max(case when slug = ? then 1 else substring(slug from ?::int)::int end) as highest',
                [$base, $base, mb_strlen($base) + 2],
            )
            ->first();

        if ((int) ($row->base_taken ?? 0) === 0) {
            return $base;
        }

        return $base.'-'.((int) $row->highest + 1);
    }

    /**
     * Whether no other live item of this type in this language uses the
     * slug. A null language is the default.
     */
    public static function isAvailable(string $type, string $slug, ?string $exceptId = null, ?string $language = null): bool
    {
        return ! DB::table('contents')
            ->where('type', $type)
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $language === null ? $query->whereNull('language') : $query->where('language', $language))
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    /**
     * Refuses a slug that is empty, too long, badly formed, or taken by
     * another live item of the same type in the same language — before any
     * save writes. A null language is the default.
     *
     * @throws ValidationException
     */
    public static function assertUsable(string $type, string $slug, string $exceptId, ?string $language = null): void
    {
        Validator::make(
            ['slug' => $slug],
            ['slug' => ['required', 'string', 'max:200', 'regex:'.self::RULE]],
        )->validate();

        if (! self::isAvailable($type, $slug, $exceptId, $language)) {
            throw ValidationException::withMessages([
                'slug' => __('Another :type already uses this address.', ['type' => RequiredFields::noun($type)]),
            ]);
        }
    }
}
