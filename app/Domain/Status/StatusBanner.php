<?php

declare(strict_types=1);

namespace App\Domain\Status;

/**
 * One Status banner: how serious it is (error, warning or info), what is
 * wrong in plain words, where to fix it, and its priority among banners.
 * It has no dismiss: it goes when the problem does.
 */
final readonly class StatusBanner
{
    public function __construct(
        public string $level,
        public string $message,
        public ?string $actionLabel,
        public ?string $actionUrl,
        public int $priority,
    ) {}

    /**
     * What the admin shares with the browser.
     *
     * @return array{level: string, message: string, actionLabel: string|null, actionUrl: string|null}
     */
    public function toArray(): array
    {
        return ['level' => $this->level, 'message' => $this->message, 'actionLabel' => $this->actionLabel, 'actionUrl' => $this->actionUrl];
    }
}
