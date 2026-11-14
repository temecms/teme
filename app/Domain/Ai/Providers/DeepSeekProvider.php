<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * DeepSeek (I-315): its key, label, place in Settings › AI, its laravel/ai
 * driver, where Check key asks, and how it is told to reason as little as it
 * can.
 */
final class DeepSeekProvider extends LibraryProvider
{
    public function key(): string
    {
        return 'deepseek';
    }

    public function label(): string
    {
        return 'DeepSeek';
    }

    public function order(): int
    {
        return 120;
    }

    protected function driver(): string
    {
        return 'deepseek';
    }

    protected function checkUrl(): string
    {
        return 'https://api.deepseek.com/models';
    }

    protected function keyHeaders(string $key): array
    {
        return ['Authorization' => "Bearer {$key}"];
    }

    // Thinking is on unless switched off.
    protected function reasoningOptions(Reasoning $reasoning): array
    {
        return match ($reasoning) {
            Reasoning::Lowest => ['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'low'],
            Reasoning::Off => ['thinking' => ['type' => 'disabled']],
            Reasoning::None => [],
        };
    }
}
