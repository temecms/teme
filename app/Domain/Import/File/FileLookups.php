<?php

/**
 * The IDs an export file's items point at by name: its authors by login and
 * its terms by taxonomy and slug. An author or term the file names only on
 * an item gets an ID below zero from a counter kept per file, and a row of
 * its own, so a resumed read gives it the same ID again.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class FileLookups
{
    /** @var list<array{list: string, id: int, payload: array<string, mixed>}> */
    private array $added = [];

    /**
     * @param  array<string, int>  $authors  by login
     * @param  array<string, int>  $terms  by TermRows::key()
     */
    public function __construct(
        private array $authors,
        private array $terms,
        private int $lowest,
    ) {}

    /**
     * Returns the author's ID by login, adding one the file names only on an item.
     */
    public function author(string $login): int
    {
        if ($login === '') {
            return 0;
        }

        if (! array_key_exists($login, $this->authors)) {
            $id = --$this->lowest;
            $this->authors[$login] = $id;
            $this->added[] = ['list' => ItemRows::USERS, 'id' => $id, 'payload' => UserRows::fromLogin($id, $login)];
        }

        return $this->authors[$login];
    }

    /**
     * Returns the term's ID by taxonomy and slug, adding one the file lists only on an item.
     */
    public function term(string $taxonomy, string $slug, string $name): int
    {
        $key = TermRows::key($taxonomy, $slug);

        if (! array_key_exists($key, $this->terms)) {
            $id = --$this->lowest;
            $this->terms[$key] = $id;
            $payload = TermRows::payload($id, $taxonomy, $name, $slug, '', 0, null);
            $this->added[] = ['list' => TermRows::listOf($taxonomy), 'id' => $id, 'payload' => $payload];
        }

        return $this->terms[$key];
    }

    /**
     * Returns the term's ID by taxonomy and slug, or null when the file has no such term.
     */
    public function known(string $taxonomy, string $slug): ?int
    {
        return $this->terms[TermRows::key($taxonomy, $slug)] ?? null;
    }

    /**
     * Returns the rows of the authors and terms added since the last call, and forgets them.
     *
     * @return list<array{list: string, id: int, payload: array<string, mixed>}>
     */
    public function takeAdded(): array
    {
        $added = $this->added;
        $this->added = [];

        return $added;
    }
}
