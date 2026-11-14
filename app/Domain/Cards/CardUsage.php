<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * The cards one public page drew, recorded while its body renders, so the
 * document shell links only those cards' styles (I-248). It is scoped to a
 * request, and start() empties it for each page, as one application may
 * serve several (B-227).
 */
final class CardUsage
{
    /** @var array<string, true> */
    private array $names = [];

    /**
     * Empties it, for a page about to be built.
     */
    public function start(): void
    {
        $this->names = [];
    }

    public function record(string $cardName): void
    {
        $this->names[$cardName] = true;
    }

    /**
     * The cards drawn, each once, in the order first drawn.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->names);
    }
}
