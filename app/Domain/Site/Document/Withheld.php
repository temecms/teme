<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

/**
 * A node the gate left out of a page's graph (I-703), and why: its @id, its
 * types, and each requirement it didn't meet, as the properties any one of
 * which would have met it. The editor's checks read these to say plainly
 * what was left out.
 */
final readonly class Withheld
{
    /**
     * @param  list<string>  $types
     * @param  list<list<string>>  $missing
     */
    public function __construct(public string $id, public array $types, public array $missing) {}
}
