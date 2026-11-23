<?php

/**
 * The rows of an export file's channel, its authors and terms, and the
 * lookups its items are read with. The channel is read again on every
 * resumed read, so the IDs it gives below zero come out the same each time;
 * those an earlier part of the read gave to items come from its rows.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class ChannelRows
{
    /**
     * Returns the channel's rows and the lookups for its items, with the
     * authors and terms the read so far added from items.
     *
     * @param  array{version: string, link: string|null, baseBlogUrl: string|null, pubDate: string|null, authors: list<array<string, string>>, terms: list<array{element: string, fields: array<string, string>, meta: list<array{key: string, value: string}>}>}  $channel
     * @param  array<string, int>  $readAuthors  by login, from rows already written
     * @param  array<string, int>  $readTerms  by TermRows::key(), from rows already written
     *
     * @return array{rows: list<array{list: string, id: int, payload: array<string, mixed>}>, lookups: FileLookups}
     */
    public static function read(array $channel, array $readAuthors = [], array $readTerms = [], int $readLowest = 0): array
    {
        $lowest = 0;
        $rows = [];
        $authors = [];

        foreach ($channel['authors'] as $author) {
            $login = trim($author['author_login'] ?? '');

            if ($login === '' || array_key_exists($login, $authors)) {
                continue;
            }

            $given = (int) trim($author['author_id'] ?? '');
            $id = $given > 0 ? $given : --$lowest;
            $authors[$login] = $id;
            $rows[] = ['list' => ItemRows::USERS, 'id' => $id, 'payload' => UserRows::fromAuthor($id, $author)];
        }

        $terms = [];
        $rows = [...$rows, ...TermRows::fromChannel($channel['terms'], $terms, $lowest)];

        return ['rows' => $rows, 'lookups' => new FileLookups($authors + $readAuthors, $terms + $readTerms, min($lowest, $readLowest))];
    }

    /**
     * Returns the site's address the file gives: its link, or its blog address when it has none.
     *
     * @param  array{version: string, link: string|null, baseBlogUrl: string|null, pubDate: string|null, authors: list<array<string, string>>, terms: list<array{element: string, fields: array<string, string>, meta: list<array{key: string, value: string}>}>}  $channel
     */
    public static function siteAddress(array $channel): ?string
    {
        foreach ([$channel['link'], $channel['baseBlogUrl']] as $address) {
            if (is_string($address) && trim($address) !== '') {
                return rtrim(trim($address), '/');
            }
        }

        return null;
    }
}
