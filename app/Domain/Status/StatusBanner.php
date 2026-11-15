<?php

declare(strict_types=1);

namespace App\Domain\Status;

/**
 * One Status banner: how serious it is (error, warning or info), what is
 * wrong in plain words, where to fix it, its priority among banners, and
 * once collected, what it is about and the plugin that raised it. It has no
 * dismiss: it goes when the problem does.
 */
final readonly class StatusBanner
{
    public function __construct(
        public string $level,
        public string $message,
        public ?string $actionLabel,
        public ?string $actionUrl,
        public int $priority,
        public string $label = '',
        public ?string $plugin = null,
    ) {}

    /** The same banner with the row's label, and the plugin's name when a plugin raised it. */
    public function labelled(string $label, ?string $plugin): self
    {
        return new self($this->level, $this->message, $this->actionLabel, $this->actionUrl, $this->priority, $label, $plugin);
    }

    /**
     * What the admin shares with the browser.
     *
     * @return array{level: string, message: string, actionLabel: string|null, actionUrl: string|null, label: string, plugin: string|null}
     */
    public function toArray(): array
    {
        return ['level' => $this->level, 'message' => $this->message, 'actionLabel' => $this->actionLabel, 'actionUrl' => $this->actionUrl, 'label' => $this->label, 'plugin' => $this->plugin];
    }
}
