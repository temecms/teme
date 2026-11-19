<?php

/**
 * A request's query parameters, each given once, as plain text, and named
 * among those its route takes (D5); anything else is refused, all at once,
 * so a typo is told and the page cache stores no copy for it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Languages\SiteLanguages;
use Illuminate\Http\Request;

final readonly class ApiQuery
{
    /** A list's size when the request names none. */
    public const int DEFAULT_LIMIT = 20;

    /** The most a list gives at once; never lowered within v1. */
    public const int MAX_LIMIT = 100;

    /** The most of a refused name an answer tells back. */
    private const int NAME_LENGTH = 64;

    /** @param array<string, string> $values */
    private function __construct(public array $values) {}

    /**
     * @param  list<string>  $allowed
     *
     * @throws InvalidParameters naming each parameter it refuses
     */
    public static function from(Request $request, array $allowed): self
    {
        $values = [];
        $problems = [];

        // The raw string: Laravel's query bag folds a repeat into one and turns '' into null.
        foreach (explode('&', (string) $request->server->get('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            // A name is told back as it can be written: bytes that aren't text never reach the answer.
            $named = self::plain($name, self::NAME_LENGTH) ? $name : self::cleaned($name);

            $problem = match (true) {
                ! in_array($name, $allowed, true) => self::listOf($name, $allowed) !== null
                    ? ApiWords::repeatedParameter((string) self::listOf($name, $allowed))
                    : ApiWords::unknownParameter($named),
                array_key_exists($name, $values) => ApiWords::repeatedParameter($name),
                ! self::plain($value) => ApiWords::badText($name),
                default => null,
            };

            if ($problem !== null) {
                $problems[$named] ??= $problem;

                continue;
            }

            $values[$name] = $value;
        }

        if ($problems !== []) {
            throw new InvalidParameters($problems);
        }

        return new self($values);
    }

    /**
     * The parameter's value as given, '' when given empty, or null when not given.
     */
    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    /**
     * A list's size: 20 when not given, else a whole number from 1 to 100
     * written plainly.
     *
     * @throws InvalidParameters naming limit
     */
    public static function limit(?string $given): int
    {
        if ($given === null) {
            return self::DEFAULT_LIMIT;
        }

        if (preg_match('/^[1-9][0-9]{0,2}$/', $given) !== 1 || (int) $given > self::MAX_LIMIT) {
            throw new InvalidParameters(['limit' => ApiWords::badLimit()]);
        }

        return (int) $given;
    }

    /**
     * The language a list asks for, as stored: null for the site's default,
     * named or not; a language the site doesn't show readers, hidden or
     * unknown alike, is noted as one problem with the same words (I-3360).
     *
     * @param  array<string, string>  $problems
     *
     * @since 1.0.0
     */
    public static function language(self $query, array &$problems): ?string
    {
        $given = $query->get('language');

        if ($given === null) {
            return null;
        }

        $languages = app(SiteLanguages::class);

        if (! in_array($given, $languages->shown(), true)) {
            $problems['language'] = ApiWords::badLanguage($languages->shown());

            return null;
        }

        return $languages->stored($given);
    }

    /**
     * Whether a name or value is text a query may hold: UTF-8, no control
     * character (C0 or DEL), and a name no longer than one can be told back.
     */
    private static function plain(string $text, int $most = PHP_INT_MAX): bool
    {
        return mb_check_encoding($text, 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', $text) !== 1 && strlen($text) <= $most;
    }

    /**
     * A refused name as an answer can carry it: invalid bytes replaced,
     * control characters removed, cut to NAME_LENGTH characters.
     */
    private static function cleaned(string $name): string
    {
        $cleaned = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', mb_scrub($name, 'UTF-8')), 0, self::NAME_LENGTH);

        return $cleaned === '' ? '?' : $cleaned;
    }

    /**
     * The parameter a name like `type[]` gives as a list, when the route
     * takes it, or null.
     *
     * @param  list<string>  $allowed
     */
    private static function listOf(string $name, array $allowed): ?string
    {
        return preg_match('/^([^\[\]]+)\[/', $name, $list) === 1 && in_array($list[1], $allowed, true) ? $list[1] : null;
    }
}
