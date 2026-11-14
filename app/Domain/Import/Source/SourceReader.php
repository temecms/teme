<?php

declare(strict_types=1);

namespace App\Domain\Import\Source;

/**
 * Reads a site Teme imports from. A reader answers the export API's routes
 * with the export API's shapes, whatever it reads from (I-1718).
 */
interface SourceReader
{
    /**
     * One keyset page of a list: the items after this ID, and where the next page starts, null at the end.
     *
     * @param  array<string, scalar>  $query
     *
     * @throws SourceRefused
     */
    public function page(string $list, array $query, int $afterId, int $limit): SourcePage;

    /**
     * A route's whole answer, for what isn't a list.
     *
     * @param  array<string, scalar>  $query
     *
     * @return array<string, mixed>
     *
     * @throws SourceRefused
     */
    public function whole(string $list, array $query = []): array;
}
