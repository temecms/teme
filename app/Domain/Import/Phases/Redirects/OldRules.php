<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Redirects;

use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\SourceRef;
use Illuminate\Support\Str;

/**
 * The redirect rules of the stores the companion reads (I-297): Redirection,
 * Rank Math, Safe Redirect Manager, Yoast SEO Premium and Simple 301
 * Redirects, each rule read as the redirects it becomes in Teme's form, with
 * what can't come said in words. Pure.
 */
final class OldRules
{
    /** Each store, in the order it's read: true for one paged by ID, false for a whole answer. */
    public const array STORES = ['redirection' => true, 'rank_math' => true, 'safe_redirect_manager' => true, 'yoast_premium' => false, 'simple_301' => false];

    /** Each store's name, as people read it. */
    public const array NAMES = ['redirection' => 'Redirection', 'rank_math' => 'Rank Math', 'safe_redirect_manager' => 'Safe Redirect Manager', 'yoast_premium' => 'Yoast SEO Premium', 'simple_301' => 'Simple 301 Redirects'];

    /** Each store's prefix to its rules' IDs in the map. */
    private const array PREFIXES = ['redirection' => 'redirection', 'rank_math' => 'rank-math', 'safe_redirect_manager' => 'safe-redirect-manager', 'yoast_premium' => 'yoast-premium', 'simple_301' => 'simple-301'];

    /** The statuses Teme's redirects answer with. */
    private const array STATUSES = [301, 302, 307, 308, 410, 451];

    /** How Rank Math's comparisons read in words. */
    private const array HOW = ['contains' => 'contain', 'end' => 'end with', 'start' => 'start with'];

    /**
     * One rule of a store, as the companion sends it: an item of a paged
     * store, or one entry of a whole answer with its key (Simple 301
     * Redirects' rules are keyed by the address they redirect from).
     */
    public static function read(string $store, mixed $item, int|string $key): OldRule
    {
        $paged = self::STORES[$store] ?? false;
        $hash = hash('sha256', (string) json_encode([$store, $paged ? null : $key, $item], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $data = is_array($item) ? $item : [];

        return match ($store) {
            'redirection' => self::redirection($data, $hash),
            'rank_math' => self::rankMath($data, $hash),
            'safe_redirect_manager' => self::safeRedirectManager($data, $hash),
            'yoast_premium' => self::yoast($data, $hash),
            default => self::simple301(self::text($item), self::text($key), $hash),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function redirection(array $item, string $hash): OldRule
    {
        $ref = self::ref('redirection', (string) (int) ($item['id'] ?? 0));
        $match = self::text($item['match_type'] ?? 'url');
        $action = self::text($item['action_type'] ?? 'url');
        $code = (int) ($item['action_code'] ?? 301);
        $regex = (bool) ($item['regex'] ?? false);

        if (self::text($item['status'] ?? 'enabled') === 'disabled') {
            return self::skipped($ref, $hash, self::switchedOff());
        }

        if ($match !== 'url' && $match !== '') {
            return self::skipped($ref, $hash, new ImportMessage('redirect.conditional', "It redirects only some visitors (by {$match}), which Teme’s redirects can’t, so it wasn’t brought.", ['match' => $match]));
        }

        if ($action === 'error') {
            return self::rule($ref, $hash, self::one(self::text($item['url'] ?? ''), '', $code, $regex));
        }

        if ($action !== 'url') {
            return self::skipped($ref, $hash, new ImportMessage('redirect.action', "It does something other than redirect ({$action}), so it wasn’t brought.", ['action' => $action]));
        }

        // As Rank Math reads it: the data's url, else its url_from, else the text.
        $data = $item['action_data'] ?? '';
        $to = is_array($data) ? self::text($data['url'] ?? $data['url_from'] ?? '') : self::text($data);

        return self::rule($ref, $hash, self::one(self::text($item['url'] ?? ''), $to, $code, $regex));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function rankMath(array $item, string $hash): OldRule
    {
        $ref = self::ref('rank_math', (string) (int) ($item['id'] ?? 0));

        if (self::text($item['status'] ?? 'active') !== 'active') {
            return self::skipped($ref, $hash, self::switchedOff());
        }

        $to = self::text($item['url_to'] ?? '');
        $code = (int) ($item['header_code'] ?? 301);
        $sources = is_array($item['sources'] ?? null) && $item['sources'] !== [] ? $item['sources'] : [['pattern' => '', 'comparison' => 'exact']];
        $read = ['redirects' => [], 'notes' => []];

        foreach ($sources as $source) {
            $pattern = is_array($source) ? self::text($source['pattern'] ?? '') : '';
            $comparison = is_array($source) ? self::text($source['comparison'] ?? 'exact') : 'exact';

            $one = match (true) {
                $comparison === 'exact' => self::one($pattern, $to, $code, false),
                $comparison === 'regex' => self::one($pattern, $to, $code, true),
                // Rank Math's start takes the address itself too.
                $comparison === 'start' && str_ends_with($pattern, '/') => self::both(self::one($pattern, $to, $code, false), self::one($pattern.'[...rest]', $to, $code, false)),
                default => self::none(self::comparison(self::HOW[$comparison] ?? $comparison, $pattern)),
            };

            $read = self::both($read, $one);
        }

        return self::rule($ref, $hash, $read);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function safeRedirectManager(array $item, string $hash): OldRule
    {
        $ref = self::ref('safe_redirect_manager', (string) (int) ($item['id'] ?? 0));

        if (self::text($item['status'] ?? 'publish') !== 'publish') {
            return self::skipped($ref, $hash, self::switchedOff());
        }

        $from = self::text($item['from'] ?? '');
        $to = self::text($item['to'] ?? '');
        $code = (int) ($item['code'] ?? 301) ?: 301;

        if ((bool) ($item['regex'] ?? false)) {
            return self::rule($ref, $hash, self::one($from, $to, $code, true));
        }

        $wild = self::wildcard($from, $to);

        return $wild === null
            ? self::skipped($ref, $hash, self::comparison(self::HOW['start'], substr($from, 0, -1)))
            : self::rule($ref, $hash, self::one($wild[0], $wild[1], $code, false));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function yoast(array $item, string $hash): OldRule
    {
        $origin = self::text($item['origin'] ?? '');
        $ref = self::ref('yoast_premium', $origin === '' ? '-' : $origin);
        $regex = self::text($item['format'] ?? 'plain') === 'regex';

        return self::rule($ref, $hash, self::one($origin, self::text($item['url'] ?? ''), (int) ($item['type'] ?? 301) ?: 301, $regex));
    }

    private static function simple301(string $to, string $from, string $hash): OldRule
    {
        $ref = self::ref('simple_301', $from === '' ? '-' : $from);
        $wild = self::wildcard($from, $to);

        return $wild === null
            ? self::skipped($ref, $hash, self::comparison(self::HOW['start'], substr($from, 0, -1)))
            : self::rule($ref, $hash, self::one($wild[0], $wild[1], 301, false));
    }

    /**
     * One source and its target in Teme's form, with what was said of it;
     * or no redirect, and why.
     *
     * @return array{redirects: list<array{from: string, to: string, status: int}>, notes: list<ImportMessage>}
     */
    private static function one(string $from, string $to, int $code, bool $regex): array
    {
        if ($code === 404) {
            return self::none(new ImportMessage('redirect.not-found-rule', 'It answered “not found” on purpose, as Teme does with no rule, so it wasn’t needed.'));
        }

        $notes = [];
        $status = $code;

        if (! in_array($code, self::STATUSES, true)) {
            $status = $code === 303 ? 302 : 301;
            $notes[] = new ImportMessage('redirect.status-changed', "It answered {$code} on the old site, which Teme’s redirects don’t, so it answers {$status} here.", ['was' => $code, 'now' => $status]);
        }

        // Patterns and addresses are cut short in words: a message holds at most 1,000 characters.
        $said = Str::limit($from, 300);

        if ($regex) {
            $converted = RegexPaths::convert($from, $to);
            $shown = mb_substr($from, 0, 500);

            if (is_string($converted)) {
                return self::none(new ImportMessage('redirect.regex', "Its pattern {$said} can’t be written as Teme’s, because {$converted}. Add it in Tools › Redirects, with [name] for one part of an address and [...rest] for the rest.", ['pattern' => $shown]));
            }

            if ($converted['widened']) {
                $became = Str::limit($converted['from'], 300);
                $notes[] = new ImportMessage('redirect.widened', "Its pattern {$said} became {$became}, which takes any text in each part, not only what the pattern allowed.", ['pattern' => $shown, 'from' => mb_substr($converted['from'], 0, 500)]);
            }

            $from = $converted['from'];
            $to = $converted['to'];
        } elseif (strpbrk($from, '?#') !== false) {
            $shown = '/'.ltrim($from, '/');
            $said = Str::limit($shown, 300);

            return self::none(new ImportMessage('redirect.query', "{$said} differs from other addresses only by what follows the ?, which Teme’s redirects can’t tell apart, so it wasn’t brought.", ['from' => mb_substr($shown, 0, 500)]));
        }

        // A 410 or 451 sends readers nowhere.
        $nowhere = in_array($status, [410, 451], true);

        return ['redirects' => [['from' => '/'.ltrim($from, '/'), 'to' => $nowhere ? '' : $to, 'status' => $status]], 'notes' => $notes];
    }

    /**
     * A trailing * as Safe Redirect Manager and Simple 301 Redirects read
     * it: the rest of the address, in the target too, where it follows a
     * slash; null where it doesn't, which Teme can't match.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function wildcard(string $from, string $to): ?array
    {
        if (! str_ends_with($from, '*')) {
            return [$from, $to];
        }

        $prefix = substr($from, 0, -1);

        return str_ends_with($prefix, '/') ? [$prefix.'[...rest]', str_replace('*', '[...rest]', $to)] : null;
    }

    /**
     * @param  array{redirects: list<array{from: string, to: string, status: int}>, notes: list<ImportMessage>}  $read
     */
    private static function rule(SourceRef $ref, string $hash, array $read): OldRule
    {
        return new OldRule($ref, $hash, $read['redirects'], $read['notes']);
    }

    private static function skipped(SourceRef $ref, string $hash, ImportMessage $why): OldRule
    {
        return new OldRule($ref, $hash, [], [$why]);
    }

    /**
     * @return array{redirects: list<array{from: string, to: string, status: int}>, notes: list<ImportMessage>}
     */
    private static function none(ImportMessage $why): array
    {
        return ['redirects' => [], 'notes' => [$why]];
    }

    /**
     * @param  array{redirects: list<array{from: string, to: string, status: int}>, notes: list<ImportMessage>}  $a
     * @param  array{redirects: list<array{from: string, to: string, status: int}>, notes: list<ImportMessage>}  $b
     *
     * @return array{redirects: list<array{from: string, to: string, status: int}>, notes: list<ImportMessage>}
     */
    private static function both(array $a, array $b): array
    {
        return ['redirects' => [...$a['redirects'], ...$b['redirects']], 'notes' => [...$a['notes'], ...$b['notes']]];
    }

    private static function comparison(string $how, string $pattern): ImportMessage
    {
        $said = Str::limit($pattern, 300);

        return new ImportMessage('redirect.comparison', "It matches addresses that {$how} {$said}, which Teme’s redirects can’t, so it wasn’t brought.", ['pattern' => mb_substr($pattern, 0, 500)]);
    }

    private static function switchedOff(): ImportMessage
    {
        return new ImportMessage('redirect.switched-off', 'It was switched off on the old site, so it wasn’t brought.');
    }

    /**
     * The rule's object: its store's prefix and its key there, a key over
     * 480 characters by its SHA-1.
     */
    private static function ref(string $store, string $key): SourceRef
    {
        return SourceRef::of('redirect', self::PREFIXES[$store].':'.(mb_strlen($key) > 480 ? sha1($key) : $key));
    }

    /**
     * A value as trimmed text: the companion sends strings, numbers or nothing.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
