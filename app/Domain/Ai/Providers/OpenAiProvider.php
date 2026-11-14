<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

/**
 * OpenAI (I-315): its key, label, place in Settings › AI, its laravel/ai
 * driver, where Check key asks, and how it is told to reason as little as it
 * can.
 */
final class OpenAiProvider extends LibraryProvider
{
    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function order(): int
    {
        return 110;
    }

    protected function driver(): string
    {
        return 'openai';
    }

    protected function checkUrl(): string
    {
        return 'https://api.openai.com/v1/models';
    }

    protected function keyHeaders(string $key): array
    {
        return ['Authorization' => "Bearer {$key}"];
    }

    // Low is the least every listed model accepts.
    protected function reasoningOptions(Reasoning $reasoning): array
    {
        return $reasoning === Reasoning::Lowest ? ['reasoning' => ['effort' => 'low']] : [];
    }
}
