<?php

declare(strict_types=1);

namespace App\Domain\Cache;

use Carbon\CarbonImmutable;

/**
 * What the page cache learns about one public page while it renders: when
 * drawing began and the number of the latest purge by then, the tags of
 * everything it read (I-263), and whether something forbids storing it,
 * such as core's plain fallback drawing it for a broken theme
 * (docs/studies/page-cache.md §3.1). Scoped to a
 * request; the page cache starts it afresh for each page it may store, as
 * one application may serve several (B-227).
 */
final class RenderRecord
{
    public const string DEGRADED = 'degraded render';

    private ?string $refusal = null;

    private ?CarbonImmutable $began = null;

    private int $latestPurge = 0;

    /** @var array<string, true> */
    private array $tags = [];

    /**
     * Empties it for a page about to be drawn, and notes when drawing began
     * and the number of the latest purge noted by then (PurgeTimes::latest()),
     * for the race guard (I-264).
     */
    public function start(int $latestPurge): void
    {
        $this->refusal = null;
        $this->tags = [];
        $this->began = now()->toImmutable();
        $this->latestPurge = $latestPurge;
    }

    /**
     * Forbids storing this page, for this reason; the first reason given is kept.
     */
    public function refuse(string $reason): void
    {
        $this->refusal ??= $reason;
    }

    /**
     * Why this page may not be stored, or null when nothing forbade it.
     */
    public function refusal(): ?string
    {
        return $this->refusal;
    }

    /**
     * Notes that the page shows what these tags stand for, while a page the
     * cache may store is being drawn; for anything else, an admin page or a
     * reader passed by, it notes nothing. `*` is every page's already.
     */
    public function tag(string ...$tags): void
    {
        if ($this->began === null) {
            return;
        }

        foreach ($tags as $tag) {
            if ($tag !== PageTags::EVERYTHING) {
                $this->tags[$tag] = true;
            }
        }
    }

    /**
     * The tags noted since start(), each once, sorted.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = array_map(strval(...), array_keys($this->tags));
        sort($tags);

        return $tags;
    }

    /**
     * When drawing the page began, or null when no page was started.
     */
    public function began(): ?CarbonImmutable
    {
        return $this->began;
    }

    /**
     * The number of the latest purge noted when drawing began: a purge
     * numbered higher came after it, whatever any server's clock says.
     */
    public function latestPurge(): int
    {
        return $this->latestPurge;
    }
}
