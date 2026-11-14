<?php

declare(strict_types=1);

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiModel;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\DeepSeekProvider;
use App\Domain\Ai\Providers\Dollars;
use App\Domain\Ai\Providers\OpenAiProvider;
use App\Domain\Ai\Providers\OpenRouterProvider;
use App\Domain\Ai\Providers\Reasoning;
use App\Domain\Ai\Providers\ShippedModels;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists each model once, with what it can do, prices that read, its reasoning, its source and the day its price was read (I-1302)', function (): void {
    $seen = [];

    foreach (ShippedModels::LIST as $row) {
        $name = "{$row['provider']}:{$row['id']}";

        expect(in_array($row['provider'], ['anthropic', 'openai', 'deepseek', 'openrouter'], true))->toBeTrue($name)
            ->and(isset($seen[$name]))->toBeFalse("{$name} is listed twice")
            ->and(trim($row['label']))->not->toBe('')
            ->and($row['can'])->not->toBe([])
            ->and(array_filter($row['can'], fn (string $can): bool => AiCapability::tryFrom($can) === null))->toBe([])
            ->and(Reasoning::tryFrom($row['reasoning']))->not->toBeNull()
            ->and($row['source'])->toStartWith('https://')
            ->and($row['checked'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and(($row['input'] === null) === ($row['output'] === null))->toBeTrue("{$name} has both prices or neither");

        foreach ([$row['input'], $row['output'], $row['cacheRead'], $row['cacheWrite']] as $price) {
            expect($price === null || Dollars::toMicro($price) !== null)->toBeTrue("{$name}'s price [{$price}]");
        }

        $seen[$name] = true;
    }
});

it('prices every model that writes text, so only another model can cost an unknown amount (I-1302)', function (): void {
    $unpriced = array_filter(ShippedModels::LIST, fn (array $row): bool => in_array('text', $row['can'], true) && $row['input'] === null);

    expect($unpriced)->toBe([]);
});

it('offers each provider its own rows of the list, in the list\'s order', function (): void {
    foreach (['anthropic' => AnthropicProvider::class, 'openai' => OpenAiProvider::class, 'deepseek' => DeepSeekProvider::class, 'openrouter' => OpenRouterProvider::class] as $key => $class) {
        $listed = array_values(array_map(fn (array $row): string => $row['id'], array_filter(ShippedModels::LIST, fn (array $row): bool => $row['provider'] === $key)));

        expect(array_map(fn (AiModel $model): string => $model->id, app($class)->models()))->toBe($listed);
    }
});

it('says where each provider stands on the capabilities no task uses yet (I-887)', function (): void {
    $later = [AiCapability::Embeddings, AiCapability::Rerank, AiCapability::ImagesOut, AiCapability::Decide];
    $stands = [];

    foreach (['anthropic', 'openai', 'deepseek', 'openrouter'] as $provider) {
        $stands[$provider] = array_values(array_map(
            fn (AiCapability $capability): string => $capability->value,
            array_filter($later, fn (AiCapability $capability): bool => array_filter(ShippedModels::for($provider), fn (AiModel $model): bool => $model->can($capability)) !== []),
        ));
    }

    expect($stands)->toBe([
        'anthropic' => [],
        'openai' => ['embeddings', 'images-out', 'decide'],
        'deepseek' => [],
        'openrouter' => ['embeddings', 'rerank', 'images-out', 'decide'],
    ]);
});

it('marks the models that reason, and how far Teme lets them (I-1360)', function (): void {
    $writers = array_filter(ShippedModels::LIST, fn (array $row): bool => in_array('text', $row['can'], true));

    expect(array_filter($writers, fn (array $row): bool => $row['reasoning'] === 'none'))->toBe([])
        ->and(ShippedModels::reasoning('anthropic', 'claude-sonnet-5-5'))->toBe(Reasoning::Lowest)
        ->and(ShippedModels::reasoning('deepseek', 'deepseek-flash'))->toBe(Reasoning::Off)
        ->and(ShippedModels::reasoning('openai', 'text-embedding-3-small'))->toBe(Reasoning::None)
        ->and(ShippedModels::reasoning('openai', 'gpt-unlisted'))->toBeNull()
        ->and(ShippedModels::has('openrouter', 'anthropic/claude-haiku-5.5'))->toBeTrue()
        ->and(ShippedModels::has('anthropic', 'anthropic/claude-haiku-5.5'))->toBeFalse()
        ->and(Reasoning::Lowest->note())->toBe('Reasons before answering: slower, and costs more.')
        ->and(Reasoning::Off->note())->toBeNull()
        ->and(Reasoning::None->note())->toBeNull();
});

it('reads a price written in US dollars as whole micro-dollars, exactly', function (string $dollars, int $micro): void {
    expect(Dollars::toMicro($dollars))->toBe($micro);
})->with([
    'whole dollars' => ['3', 3_000_000],
    'cents' => ['0.25', 250_000],
    'a fraction of a cent' => ['0.125', 125_000],
    'spaces around it' => [' 12.50 ', 12_500_000],
    'one micro-dollar' => ['0.000001', 1],
    'nothing' => ['0', 0],
]);

it('refuses a price it can\'t read exactly', function (string $dollars): void {
    expect(Dollars::toMicro($dollars))->toBeNull();
})->with([
    'empty' => [''],
    'below zero' => ['-1'],
    'a dot with nothing after it' => ['1.'],
    'a dot with nothing before it' => ['.5'],
    'words' => ['3 dollars'],
    'finer than a micro-dollar' => ['0.0000001'],
    'a thousands separator' => ['1,000'],
]);
