<?php

/**
 * The code a region's Custom HTML cards hold: read from a document, compared
 * between two, hidden from whoever may not read it, and carried over from
 * the revision before for whoever may not change it.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The code a region's Custom HTML cards hold (docs/architecture/security.md).
 * Each card keeps its own `code` and the `origins` it connects to; only someone who may manage site code adds or changes them.
 * Everyone else's documents come in with the code hidden, as null, and
 * leave with the code each card had before; a card new to the region holds
 * none.
 */
final class RegionCode
{
    /** The card that holds code. */
    public const string CARD = 'site-code';

    /** What a card holds when it holds no code. */
    private const array NONE = ['code' => '', 'origins' => []];

    /**
     * Whether this person may add or change a card's code now: they manage
     * site code, and the host hasn't switched it off.
     */
    public static function manages(?User $actor): bool
    {
        return $actor !== null && $actor->can(SiteCode::ABILITY) && ! SiteCode::disallowed();
    }

    /**
     * Each card's code and origins, by its node ID; a card without an ID
     * by "new:N", so it counts as added.
     *
     * @param  array<mixed>  $document
     *
     * @return array<string, array{code: string, origins: list<string>}>
     */
    public static function of(array $document): array
    {
        $cards = [];
        $new = 0;

        self::walk($document, function (array $node) use (&$cards, &$new): array {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $id = is_string($attrs['id'] ?? null) && $attrs['id'] !== '' ? $attrs['id'] : 'new:'.$new++;
            $cards[$id] = self::held($attrs);

            return $node;
        });

        return $cards;
    }

    /**
     * What changed between two documents' code, card by card: "added" where
     * a card now holds code it didn't, "removed" where it no longer holds
     * any, "changed" otherwise. A card that holds no code on either side
     * changes nothing.
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     *
     * @return list<array{kind: 'added'|'changed'|'removed', id: string, before: array{code: string, origins: list<string>}, after: array{code: string, origins: list<string>}}>
     */
    public static function changes(array $before, array $after): array
    {
        $old = self::of($before);
        $new = self::of($after);
        $changes = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $id) {
            $was = $old[$id] ?? self::NONE;
            $is = $new[$id] ?? self::NONE;

            if ($was === $is || (self::empty($was) && self::empty($is))) {
                continue;
            }

            $changes[] = [
                'kind' => self::empty($was) ? 'added' : (self::empty($is) ? 'removed' : 'changed'),
                'id' => (string) $id,
                'before' => $was,
                'after' => $is,
            ];
        }

        return $changes;
    }

    /**
     * The changes that put code on the site or alter it, without removals.
     *
     * @param  list<array{kind: 'added'|'changed'|'removed', id: string, before: array{code: string, origins: list<string>}, after: array{code: string, origins: list<string>}}>  $changes
     */
    public static function addsOrChanges(array $changes): bool
    {
        return array_any($changes, fn (array $change): bool => $change['kind'] !== 'removed');
    }

    /**
     * The document with every card's code and origins hidden, for whoever
     * may not read them.
     *
     * @param  array<mixed>  $document
     *
     * @return array<mixed>
     */
    public static function redact(array $document): array
    {
        return self::walk($document, function (array $node): array {
            $node['attrs'] = [...(is_array($node['attrs'] ?? null) ? $node['attrs'] : []), 'code' => null, 'origins' => []];

            return $node;
        });
    }

    /**
     * The document of someone who may not change code, each card given the
     * code it had in $base, by its ID, and a card new to the region none.
     * Hidden values, null or empty, are taken as "keep"; any other value
     * that differs is refused, and so are two cards with one ID.
     *
     * @param  array<mixed>  $document
     * @param  array<mixed>  $base
     *
     * @return array<mixed>
     *
     * @throws AuthorizationException
     */
    public static function carry(array $document, array $base, string $refusal = 'Not permitted to change site code.'): array
    {
        $kept = self::of($base);
        $seen = [];

        return self::walk($document, function (array $node) use ($kept, $refusal, &$seen): array {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $id = is_string($attrs['id'] ?? null) ? $attrs['id'] : '';

            // Two cards with one ID would both take the first one's code.
            if ($id !== '' && isset($seen[$id])) {
                throw new AuthorizationException($refusal);
            }

            $seen[$id] = true;
            $was = $kept[$id] ?? self::NONE;
            $code = $attrs['code'] ?? null;
            $origins = $attrs['origins'] ?? null;

            if ((is_string($code) && $code !== '' && $code !== $was['code'])
                || (! is_string($code) && $code !== null)
                || (is_array($origins) && $origins !== [] && self::origins($origins) !== $was['origins'])
                || ($origins !== null && ! is_array($origins))) {
                throw new AuthorizationException($refusal);
            }

            // A card no longer has an on or off, so one that is sent is dropped.
            unset($attrs['on']);
            $node['attrs'] = [...$attrs, 'code' => $was['code'] === '' ? null : $was['code'], 'origins' => $was['origins']];

            return $node;
        });
    }

    /**
     * Hosts as a card keeps them: trimmed, in lower case, each once.
     *
     * @return list<string>
     */
    public static function origins(mixed $origins): array
    {
        if (! is_array($origins)) {
            return [];
        }

        $hosts = array_map(fn (mixed $host): string => strtolower(trim(is_string($host) ? $host : '')), $origins);

        return array_values(array_unique(array_filter($hosts, fn (string $host): bool => $host !== '')));
    }

    /**
     * @param  array<mixed>  $attrs
     *
     * @return array{code: string, origins: list<string>}
     */
    private static function held(array $attrs): array
    {
        return [
            'code' => is_string($attrs['code'] ?? null) ? $attrs['code'] : '',
            'origins' => self::origins($attrs['origins'] ?? []),
        ];
    }

    /**
     * @param  array{code: string, origins: list<string>}  $held
     */
    private static function empty(array $held): bool
    {
        return $held['code'] === '' && $held['origins'] === [];
    }

    /**
     * The document with $change applied to every Custom HTML card, at any depth.
     *
     * @param  array<mixed>  $node
     * @param  callable(array<mixed>): array<mixed>  $change
     *
     * @return array<mixed>
     */
    private static function walk(array $node, callable $change): array
    {
        if (($node['type'] ?? null) === self::CARD) {
            $node = $change($node);
        }

        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $index => $child) {
                if (is_array($child)) {
                    $node['content'][$index] = self::walk($child, $change);
                }
            }
        }

        return $node;
    }
}
