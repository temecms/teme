<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * Anthropic (I-315): its key, label, place in Settings › AI, its laravel/ai
 * driver, where Check key asks, and how it is told to reason as little as it
 * can.
 */
final class AnthropicProvider extends LibraryProvider
{
    public function key(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Anthropic';
    }

    public function order(): int
    {
        return 100;
    }

    protected function driver(): string
    {
        return 'anthropic';
    }

    protected function checkUrl(): string
    {
        return 'https://api.anthropic.com/v1/models';
    }

    protected function keyHeaders(string $key): array
    {
        return ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'];
    }

    // Effort steers its adaptive thinking; some of its
    // models refuse to switch thinking off, so Teme never asks.
    protected function reasoningOptions(Reasoning $reasoning): array
    {
        return $reasoning === Reasoning::Lowest ? ['output_config' => ['effort' => 'low']] : [];
    }
}
