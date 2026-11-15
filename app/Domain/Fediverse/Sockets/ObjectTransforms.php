<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Contents\Models\Content;

/**
 * Where plugins change what core sends: an item's object and an actor's
 * document, each with the keys that say who and where kept as core wrote
 * them.
 */
final readonly class ObjectTransforms
{
    /**
     * The item's object as plugins leave it.
     *
     * @param  array<string, mixed>  $object
     *
     * @return array<string, mixed>
     */
    public function object(Content $item, array $object): array
    {
        return $object;
    }
}
