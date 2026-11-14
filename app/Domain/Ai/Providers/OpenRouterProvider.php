<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * OpenRouter (I-315): its key, label, place in Settings › AI, its laravel/ai
 * driver, where Check key asks, and how it is told to reason as little as it
 * can.
 */
final class OpenRouterProvider extends LibraryProvider
{
    public function key(): string
    {
        return 'openrouter';
    }

    public function label(): string
    {
        return 'OpenRouter';
    }

    public function order(): int
    {
        return 130;
    }

    protected function driver(): string
    {
        return 'openrouter';
    }

    protected function checkUrl(): string
    {
        return 'https://openrouter.ai/api/v1/key';
    }

    protected function keyHeaders(string $key): array
    {
        return ['Authorization' => "Bearer {$key}"];
    }

    // One control for every model it routes.
    protected function reasoningOptions(Reasoning $reasoning): array
    {
        return match ($reasoning) {
            Reasoning::Lowest => ['reasoning' => ['effort' => 'low']],
            Reasoning::Off => ['reasoning' => ['enabled' => false]],
            Reasoning::None => [],
        };
    }
}
