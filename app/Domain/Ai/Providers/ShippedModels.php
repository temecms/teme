<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiModel;
use App\Domain\Ai\AiPrice;
use UnexpectedValueException;

/**
 * The models Teme offers for each provider, updated for each release (I-1302):
 * what each can do, its prices in US dollars per million tokens (input,
 * output, cache reads, cache writes) and whether it reasons. Every price is
 * copied by hand from the page named, on the day given, and never fetched at
 * run time. Where a page gives two prices, the higher, so spend is never
 * under-counted. A model with no price records its tokens and costs an
 * unknown amount.
 */
final class ShippedModels
{
    private const string ANTHROPIC = 'https://platform.claude.com/docs/en/about-claude/pricing';

    private const string OPENAI = 'https://developers.openai.com/api/docs/pricing';

    // Peak-hour prices: off-peak hours cost half.
    private const string DEEPSEEK = 'https://api-docs.deepseek.com/quick_start/pricing';

    private const string OPENROUTER = 'https://openrouter.ai/api/v1/models';

    // The library's own defaults for OpenRouter, not priced by OpenRouter's list.
    private const string OPENROUTER_DEFAULTS = 'https://github.com/laravel/ai/blob/v1.2.0/src/Providers/OpenRouterProvider.php';

    private const array WRITES = ['text', 'images-in', 'structured-output'];

    /**
     * @var list<array{provider: string, id: string, label: string, can: list<string>, input: ?string, output: ?string, cacheRead: ?string, cacheWrite: ?string, reasoning: string, source: string, checked: string}>
     */
    public const array LIST = [
        ['provider' => 'anthropic', 'id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'can' => self::WRITES, 'input' => '4', 'output' => '20', 'cacheRead' => '0.20', 'cacheWrite' => '5', 'reasoning' => 'lowest', 'source' => self::ANTHROPIC, 'checked' => '2026-10-08'],
        ['provider' => 'anthropic', 'id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'can' => self::WRITES, 'input' => '2', 'output' => '10', 'cacheRead' => '0.10', 'cacheWrite' => '2.50', 'reasoning' => 'lowest', 'source' => self::ANTHROPIC, 'checked' => '2026-10-08'],
        // Prompts up to 100,000 tokens; longer ones cost five times as much.
        ['provider' => 'anthropic', 'id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'can' => self::WRITES, 'input' => '0.10', 'output' => '0.50', 'cacheRead' => '0.01', 'cacheWrite' => '0.125', 'reasoning' => 'lowest', 'source' => self::ANTHROPIC, 'checked' => '2026-10-08'],

        ['provider' => 'openai', 'id' => 'gpt-6-astra', 'label' => 'GPT-6 Astra', 'can' => self::WRITES, 'input' => '10', 'output' => '50', 'cacheRead' => '1', 'cacheWrite' => '12.50', 'reasoning' => 'lowest', 'source' => self::OPENAI, 'checked' => '2026-10-08'],
        ['provider' => 'openai', 'id' => 'gpt-6.1-sol', 'label' => 'GPT-6.1 Sol', 'can' => self::WRITES, 'input' => '2', 'output' => '10', 'cacheRead' => '0.10', 'cacheWrite' => '2.50', 'reasoning' => 'lowest', 'source' => self::OPENAI, 'checked' => '2026-10-08'],
        // Also answers decisions, through OpenAI's Decisions API (I-1319).
        ['provider' => 'openai', 'id' => 'gpt-6-luna', 'label' => 'GPT-6 Luna', 'can' => [...self::WRITES, 'decide'], 'input' => '0.10', 'output' => '0.50', 'cacheRead' => '0.01', 'cacheWrite' => '0.125', 'reasoning' => 'lowest', 'source' => self::OPENAI, 'checked' => '2026-10-08'],
        ['provider' => 'openai', 'id' => 'text-embedding-3-small', 'label' => 'Text embedding 3 small', 'can' => ['embeddings'], 'input' => '0.02', 'output' => '0', 'cacheRead' => null, 'cacheWrite' => null, 'reasoning' => 'none', 'source' => self::OPENAI, 'checked' => '2026-10-08'],
        ['provider' => 'openai', 'id' => 'text-embedding-3-large', 'label' => 'Text embedding 3 large', 'can' => ['embeddings'], 'input' => '0.13', 'output' => '0', 'cacheRead' => null, 'cacheWrite' => null, 'reasoning' => 'none', 'source' => self::OPENAI, 'checked' => '2026-10-08'],
        // Image tokens are priced apart from text tokens, which AiPrice can't tell apart.
        ['provider' => 'openai', 'id' => 'gpt-image-2', 'label' => 'GPT Image 2', 'can' => ['images-in', 'images-out'], 'input' => null, 'output' => null, 'cacheRead' => null, 'cacheWrite' => null, 'reasoning' => 'none', 'source' => self::OPENAI, 'checked' => '2026-10-08'],

        ['provider' => 'deepseek', 'id' => 'deepseek-flash', 'label' => 'DeepSeek V4.1 Flash', 'can' => self::WRITES, 'input' => '0.30', 'output' => '1.20', 'cacheRead' => '0.006', 'cacheWrite' => null, 'reasoning' => 'off', 'source' => self::DEEPSEEK, 'checked' => '2026-10-08'],
        ['provider' => 'deepseek', 'id' => 'deepseek-v4-pro', 'label' => 'DeepSeek V4 Pro', 'can' => ['text', 'structured-output'], 'input' => '1.32', 'output' => '3.96', 'cacheRead' => '0.044', 'cacheWrite' => null, 'reasoning' => 'off', 'source' => self::DEEPSEEK, 'checked' => '2026-10-08'],

        ['provider' => 'openrouter', 'id' => 'anthropic/claude-sonnet-5.5', 'label' => 'Claude Sonnet 5.5', 'can' => self::WRITES, 'input' => '2', 'output' => '10', 'cacheRead' => '0.10', 'cacheWrite' => '2.50', 'reasoning' => 'lowest', 'source' => self::OPENROUTER, 'checked' => '2026-10-08'],
        ['provider' => 'openrouter', 'id' => 'anthropic/claude-haiku-5.5', 'label' => 'Claude Haiku 5.5', 'can' => self::WRITES, 'input' => '0.10', 'output' => '0.50', 'cacheRead' => '0.01', 'cacheWrite' => '0.125', 'reasoning' => 'lowest', 'source' => self::OPENROUTER, 'checked' => '2026-10-08'],
        ['provider' => 'openrouter', 'id' => 'openai/gpt-6-luna', 'label' => 'GPT-6 Luna', 'can' => self::WRITES, 'input' => '0.10', 'output' => '0.50', 'cacheRead' => '0.01', 'cacheWrite' => '0.125', 'reasoning' => 'lowest', 'source' => self::OPENROUTER, 'checked' => '2026-10-08'],
        ['provider' => 'openrouter', 'id' => 'deepseek/deepseek-v4.1-flash', 'label' => 'DeepSeek V4.1 Flash', 'can' => self::WRITES, 'input' => '0.30', 'output' => '1.20', 'cacheRead' => '0.006', 'cacheWrite' => null, 'reasoning' => 'lowest', 'source' => self::OPENROUTER, 'checked' => '2026-10-08'],
        ['provider' => 'openrouter', 'id' => 'google/gemini-embedding-001', 'label' => 'Gemini embedding 001', 'can' => ['embeddings'], 'input' => null, 'output' => null, 'cacheRead' => null, 'cacheWrite' => null, 'reasoning' => 'none', 'source' => self::OPENROUTER_DEFAULTS, 'checked' => '2026-10-08'],
        ['provider' => 'openrouter', 'id' => 'cohere/rerank-4-pro', 'label' => 'Cohere Rerank 4 Pro', 'can' => ['rerank'], 'input' => null, 'output' => null, 'cacheRead' => null, 'cacheWrite' => null, 'reasoning' => 'none', 'source' => self::OPENROUTER_DEFAULTS, 'checked' => '2026-10-08'],
        ['provider' => 'openrouter', 'id' => 'google/gemini-3.1-flash-image', 'label' => 'Gemini 3.1 Flash Image', 'can' => ['images-in', 'images-out'], 'input' => null, 'output' => null, 'cacheRead' => null, 'cacheWrite' => null, 'reasoning' => 'none', 'source' => self::OPENROUTER, 'checked' => '2026-10-08'],
        // TypeSafe's Jev, through OpenRouter's Decisions API (I-1319).
        ['provider' => 'openrouter', 'id' => '~typesafe/jev-latest', 'label' => 'Jev (TypeSafe)', 'can' => ['decide'], 'input' => null, 'output' => null, 'cacheRead' => null, 'cacheWrite' => null, 'reasoning' => 'none', 'source' => self::OPENROUTER_DEFAULTS, 'checked' => '2026-10-08'],
    ];

    /**
     * The provider's models, in the list's order.
     *
     * @return list<AiModel>
     */
    public static function for(string $provider): array
    {
        $models = [];

        foreach (self::LIST as $row) {
            if ($row['provider'] === $provider) {
                $models[] = new AiModel($row['id'], $row['label'], array_map(self::capability(...), $row['can']), self::price($row));
            }
        }

        return $models;
    }

    /**
     * Whether the provider's list has a model with this id.
     */
    public static function has(string $provider, string $id): bool
    {
        return self::reasoning($provider, $id) !== null;
    }

    /**
     * How a listed model reasons, or null when the provider's list hasn't it.
     */
    public static function reasoning(string $provider, string $id): ?Reasoning
    {
        foreach (self::LIST as $row) {
            if ($row['provider'] === $provider && $row['id'] === $id) {
                return Reasoning::from($row['reasoning']);
            }
        }

        return null;
    }

    private static function capability(string $value): AiCapability
    {
        return AiCapability::tryFrom($value) ?? throw new UnexpectedValueException("The model list's capability [{$value}] isn't one.");
    }

    /**
     * @param  array{input: ?string, output: ?string, cacheRead: ?string, cacheWrite: ?string}  $row
     */
    private static function price(array $row): ?AiPrice
    {
        if ($row['input'] === null || $row['output'] === null) {
            return null;
        }

        return new AiPrice(
            self::micro($row['input']),
            self::micro($row['output']),
            $row['cacheRead'] === null ? null : self::micro($row['cacheRead']),
            $row['cacheWrite'] === null ? null : self::micro($row['cacheWrite']),
        );
    }

    private static function micro(string $dollars): int
    {
        return Dollars::toMicro($dollars) ?? throw new UnexpectedValueException("The model list's price [{$dollars}] isn't one.");
    }
}
