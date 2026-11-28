<?php

/**
 * Terms of a taxonomy as a plugin's TermWriter lists them.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Taxonomy\Queries;

use App\Domain\Hooks\Payloads;
use App\Domain\Taxonomy\Models\Term;
use Teme\Contracts\Taxonomy\TermRef;

final class PluginTerms
{
    /**
     * The terms of this taxonomy, oldest first.
     *
     * @return list<TermRef>
     */
    public function of(string $taxonomy): array
    {
        return Term::query()
            ->where('taxonomy', $taxonomy)
            ->orderBy('id')
            ->get()
            ->map(fn (Term $term): TermRef => Payloads::term($term))
            ->all();
    }

    /**
     * One term by ID, or null when it is missing.
     */
    public function find(string $termId): ?TermRef
    {
        $term = Term::query()->find($termId);

        return $term === null ? null : Payloads::term($term);
    }
}
