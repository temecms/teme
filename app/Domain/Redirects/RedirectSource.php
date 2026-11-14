<?php

declare(strict_types=1);

namespace App\Domain\Redirects;

use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;

/**
 * Where a redirect starts, read from what someone typed (I-297): a path on
 * this site in the path table's own form, exact or a pattern of [name] and
 * [...rest] placeholders (I-287), compiled once into a regex PCRE and
 * PostgreSQL read alike.
 */
final readonly class RedirectSource
{
    /** A placeholder: [name] for one part of an address, [...rest] for the rest of it. */
    public const string PLACEHOLDER = '/\[(\.\.\.)?([a-z][a-z0-9_]*)\]/';

    /** The longest source or target kept, in characters. */
    public const int LONGEST = 2000;

    /**
     * @param  list<string>  $names  the placeholders' names, in order
     */
    private function __construct(
        public string $path,
        public bool $isPattern,
        public ?string $regex,
        public array $names,
    ) {}

    /**
     * The source this text names, or a refusal saying why it can't be one.
     *
     * @throws RedirectRefused
     */
    public static function read(string $typed): self
    {
        $typed = trim($typed);

        if ($typed === '') {
            throw new RedirectRefused('source', __('Enter the address to redirect from, starting /.'));
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $typed) === 1) {
            $typed = self::onThisSite($typed) ?? throw new RedirectRefused('source', __('A redirect starts on this site. Enter just the path, starting /.'));
        }

        if (! str_starts_with($typed, '/')) {
            throw new RedirectRefused('source', __('Start the address with /, as in /old-page.'));
        }

        if (strpbrk($typed, '?#') !== false) {
            throw new RedirectRefused('source', __('Enter the path alone, without ? or # and what follows.'));
        }

        if (! self::readable(rawurldecode($typed))) {
            throw new RedirectRefused('source', __('That address holds characters no address can.'));
        }

        $path = PublicRequestPath::normalise($typed);

        if ($path === '') {
            throw new RedirectRefused('source', __('The home page always answers, so a redirect from / would send nobody on. Enter another address.'));
        }

        if (mb_strlen($path) > self::LONGEST) {
            throw new RedirectRefused('source', __('Enter an address of 2,000 characters or fewer.'));
        }

        // The router answers these before any redirect could.
        if (Paths::taken(explode('/', $path)[0])) {
            throw new RedirectRefused('source', __('The address :address is used by the site itself.', ['address' => '/'.$path.'/']));
        }

        return self::shaped($path);
    }

    /**
     * The regex a path with placeholders compiles to: each literal part with
     * every character but a letter, digit, underscore, hyphen or space
     * escaped by a backslash, which PCRE and PostgreSQL both read as the
     * character itself (I-287).
     */
    public static function compile(string $path): string
    {
        $parts = array_map(
            fn (string $segment): string => match (true) {
                preg_match('/^\[\.\.\.[a-z][a-z0-9_]*\]$/', $segment) === 1 => '(.+)',
                preg_match('/^\[[a-z][a-z0-9_]*\]$/', $segment) === 1 => '([^/]+)',
                default => (string) preg_replace('/[^\p{L}\p{N}_ -]/u', '\\\\$0', $segment),
            },
            explode('/', $path),
        );

        return '^'.implode('/', $parts).'$';
    }

    /**
     * The path with a sample in each placeholder, to see where a pattern leads.
     */
    public static function sample(string $path): string
    {
        return (string) preg_replace(['/\[\.\.\.[a-z][a-z0-9_]*\]/', '/\[[a-z][a-z0-9_]*\]/'], ['teme-sample/teme-more', 'teme-sample'], $path);
    }

    /**
     * The placeholders' values when the regex matches the path, or null.
     *
     * @param  list<string>  $names
     *
     * @return array<string, string>|null
     */
    public static function captures(string $regex, array $names, string $path): ?array
    {
        if (preg_match('#'.$regex.'#u', $path, $found) !== 1) {
            return null;
        }

        return array_combine($names, array_slice($found, 1, count($names)));
    }

    /**
     * The path of an address on this site typed in full, its scheme and host
     * taken off (rule 9), or null for another site's.
     */
    public static function onThisSite(string $address): ?string
    {
        $site = parse_url((string) config('app.url'));
        $typed = parse_url($address);

        if (! is_array($typed) || ! is_array($site) || ! isset($typed['host'], $site['host'])
            || strtolower($typed['host']) !== strtolower($site['host']) || ($typed['port'] ?? null) !== ($site['port'] ?? null)) {
            return null;
        }

        return ($typed['path'] ?? '/').(isset($typed['query']) ? '?'.$typed['query'] : '').(isset($typed['fragment']) ? '#'.$typed['fragment'] : '');
    }

    /**
     * Whether text could be part of an address: UTF-8, with no control
     * character, which a browser would drop and so read another address.
     */
    public static function readable(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', $text) !== 1;
    }

    /**
     * The placeholders' values when this source matches a path in its own
     * form: an empty array for an exact match, or null.
     *
     * @return array<string, string>|null
     */
    public function matches(string $path): ?array
    {
        if ($this->regex === null) {
            return $path === $this->path ? [] : null;
        }

        return self::captures($this->regex, $this->names, $path);
    }

    /** The source as people read it: a slash, then the path. */
    public function shown(): string
    {
        return '/'.$this->path;
    }

    /**
     * An exact source, or a pattern whose placeholders each fill a whole
     * part, with [...rest] only at the end and no name twice.
     */
    private static function shaped(string $path): self
    {
        if (preg_match('/[\[\]]/', (string) preg_replace(self::PLACEHOLDER, '', $path)) === 1) {
            throw new RedirectRefused('source', __('Write each placeholder as [name] or [...rest], in lower-case letters.'));
        }

        $segments = explode('/', $path);
        $names = [];

        foreach ($segments as $index => $segment) {
            if (preg_match(self::PLACEHOLDER, $segment, $holder) !== 1) {
                continue;
            }

            if ($holder[0] !== $segment) {
                throw new RedirectRefused('source', __('A placeholder takes a whole part of the address, between two slashes, as in /2019/[name].'));
            }

            if ($holder[1] !== '' && $index !== array_key_last($segments)) {
                throw new RedirectRefused('source', __('[...rest] takes everything after it, so it can only end the address.'));
            }

            if (in_array($holder[2], $names, true)) {
                throw new RedirectRefused('source', __('Each placeholder needs a name of its own: [:name] is used twice.', ['name' => $holder[2]]));
            }

            $names[] = $holder[2];
        }

        return new self($path, $names !== [], $names === [] ? null : self::compile($path), $names);
    }
}
