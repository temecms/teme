<?php

declare(strict_types=1);

namespace App\Domain\Site\Document;

use Illuminate\Contracts\Container\Container;

/**
 * The fragments every placement contributor gives one placement of the page
 * (I-228), read plugin, then site, then core, each key's first kept, for the
 * document shell to write as given.
 */
final readonly class Placements
{
    /** The container tag a placement contributor is registered under, from its own provider's register(). */
    public const string CONTRIBUTORS = 'teme.document.placements';

    public function __construct(private Container $container) {}

    /**
     * This placement's fragments on this page, in the order they were read.
     *
     * @return list<Fragment>
     */
    public function for(Placement $placement, DocumentPage $page): array
    {
        $kept = [];

        foreach (Precedence::order($this->container->tagged(self::CONTRIBUTORS), PlacementContributor::class) as $contributor) {
            foreach ($contributor->fragments($placement, $page) as $fragment) {
                $kept[$fragment->key] ??= $fragment;
            }
        }

        // Later (I-697): a plugin's fragments lose their JSON-LD scripts and on* attributes here, and their inline scripts are hashed from the Fragment, never from the page.

        return array_values($kept);
    }
}
