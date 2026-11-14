<?php

declare(strict_types=1);

namespace App\Domain\Site;

/**
 * What went wrong in drawing one public page, for `X-Teme-Warnings` outside
 * production: an image context the theme doesn't declare, a missing image,
 * and how many images asked to load first, of which a page wants exactly
 * one. It is scoped to a request, and start() empties it for each page, as
 * one application may serve several (B-227).
 */
final class PageWarnings
{
    /** @var list<string> */
    private array $messages = [];

    private int $priorities = 0;

    /**
     * Empties it, for a page about to be built.
     */
    public function start(): void
    {
        $this->messages = [];
        $this->priorities = 0;
    }

    public function add(string $message): void
    {
        if (! in_array($message, $this->messages, true)) {
            $this->messages[] = $message;
        }
    }

    /**
     * Records one image drawn to load first.
     */
    public function priority(): void
    {
        $this->priorities++;
    }

    /**
     * How many images the page drew to load first.
     */
    public function priorityCount(): int
    {
        return $this->priorities;
    }

    /**
     * Every warning, in the order it was added.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->messages;
    }
}
