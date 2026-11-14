<?php

declare(strict_types=1);

use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiImage;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\ModelRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('answers with the text, counting each token once and reasoning as the output it\'s billed as, at the list\'s price (I-1360)', function (string $provider, string $model, string $host, int $cacheWrite, int $cost): void {
    aiKey("{$provider}_key", "sk-{$provider}-test");
    // 500 tokens written, 300 of them reasoning.
    Http::fake(["{$host}/*" => Http::response(aiReply($provider, ['model' => "{$model}-2026", 'input' => 1_000, 'output' => 500, 'cacheRead' => 2_000, 'cacheWrite' => $cacheWrite, 'reasoning' => 300]))]);

    $result = app(AiProviders::class)->find($provider)?->complete(aiRequest($model));

    expect($result?->text)->toBe('Shorter.')
        ->and($result?->model)->toBe("{$model}-2026")
        ->and($result?->usage)->toEqual(new AiUsage(input: 1_000, output: 500, cacheRead: 2_000, cacheWrite: $cacheWrite))
        ->and(app(AiProviders::class)->cost(new ModelRef($provider, $model), $result?->usage ?? new AiUsage))->toBe($cost);
})->with([
    // $2 in, $10 out, $0.10 cache reads, $2.50 cache writes, a million tokens.
    'Anthropic' => ['anthropic', 'claude-sonnet-5-5', 'api.anthropic.com', 400, 8_200],
    // $0.10, $0.50, $0.01, $0.125.
    'OpenAI' => ['openai', 'gpt-6-luna', 'api.openai.com', 400, 420],
    // $0.30, $1.20, $0.006; no cache writes.
    'DeepSeek' => ['deepseek', 'deepseek-flash', 'api.deepseek.com', 0, 912],
    'OpenRouter' => ['openrouter', 'anthropic/claude-haiku-5.5', 'openrouter.ai', 400, 420],
]);

it('asks the chosen model with its key, the answer\'s limit and the least reasoning the model allows (I-1360)', function (string $provider, string $model, string $host, string $keyHeader, string $limitField, int $limit, string $optionPath, string $option): void {
    aiKey("{$provider}_key", "sk-{$provider}-test");
    Http::fake(["{$host}/*" => Http::response(aiReply($provider))]);

    app(AiProviders::class)->find($provider)?->complete(aiRequest($model));

    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === $host
        && str_contains($request->header($keyHeader)[0] ?? '', "sk-{$provider}-test")
        && data_get($request->data(), 'model') === $model
        && data_get($request->data(), $limitField) === $limit
        && data_get($request->data(), $optionPath) === $option);
})->with([
    'Anthropic, its effort low' => ['anthropic', 'claude-sonnet-5-5', 'api.anthropic.com', 'x-api-key', 'max_tokens', 4_296, 'output_config.effort', 'low'],
    'OpenAI, its reasoning effort low' => ['openai', 'gpt-6-luna', 'api.openai.com', 'Authorization', 'max_output_tokens', 4_296, 'reasoning.effort', 'low'],
    'DeepSeek, its thinking off' => ['deepseek', 'deepseek-flash', 'api.deepseek.com', 'Authorization', 'max_completion_tokens', 200, 'thinking.type', 'disabled'],
    'OpenRouter, its reasoning effort low' => ['openrouter', 'anthropic/claude-haiku-5.5', 'openrouter.ai', 'Authorization', 'max_tokens', 4_296, 'reasoning.effort', 'low'],
]);

it('asks another model as typed, with no reasoning options unless the administrator says it reasons (I-1360)', function (): void {
    aiKey('anthropic_key', 'sk-anthropic-test');
    Http::fake(['api.anthropic.com/*' => Http::response(aiReply('anthropic'))]);
    cmSettings(['anthropic_other_model' => 'claude-next', 'anthropic_other_abilities' => ['text']], AiSettings::KEY);

    app(AiProviders::class)->find('anthropic')?->complete(aiRequest('claude-next'));
    cmSettings(['anthropic_other_reasons' => true], AiSettings::KEY);
    app(AiProviders::class)->find('anthropic')?->complete(aiRequest('claude-next'));

    $sent = Http::recorded()->map(fn (array $pair): array => [data_get($pair[0]->data(), 'max_tokens'), data_get($pair[0]->data(), 'output_config.effort')])->all();

    expect($sent)->toBe([[200, null], [4_296, 'low']]);
});

it('sends the system message as instructions, the material as the user\'s, and images as bytes, never a link (I-319)', function (): void {
    aiKey('anthropic_key', 'sk-anthropic-test');
    Http::fake(['api.anthropic.com/*' => Http::response(aiReply('anthropic'))]);

    app(AiProviders::class)->find('anthropic')?->complete(aiRequest('claude-sonnet-5-5', images: [new AiImage('image/png', 'png-bytes')]));

    Http::assertSent(fn (Request $request): bool => str_contains((string) json_encode(data_get($request->data(), 'system')), 'Rewrite the text more briefly.')
        && str_contains($request->body(), 'A rather long sentence about the ferry.')
        && str_contains($request->body(), base64_encode('png-bytes'))
        && ! str_contains($request->body(), '"url"'));
});

it('answers in the shape asked for, as data (I-316)', function (): void {
    aiKey('anthropic_key', 'sk-anthropic-test');
    Http::fake(['api.anthropic.com/*' => Http::response(aiReply('anthropic', ['text' => '{"alt":"A ferry at dawn"}']))]);

    $result = app(AiProviders::class)->find('anthropic')?->complete(aiRequest('claude-sonnet-5-5', ['type' => 'object', 'properties' => ['alt' => ['type' => 'string', 'maxLength' => 150]], 'required' => ['alt']]));

    expect($result?->data)->toBe(['alt' => 'A ferry at dawn'])
        ->and($result?->text)->toBeNull();
});

it('uses the key the server sets over the one stored (I-315)', function (): void {
    aiKey('anthropic_key', 'sk-stored');
    Http::fake(['api.anthropic.com/*' => Http::response(aiReply('anthropic'))]);

    try {
        aiServerKey('TEME_AI_ANTHROPIC_KEY', 'sk-from-the-server');
        app(AiProviders::class)->find('anthropic')?->complete(aiRequest('claude-sonnet-5-5'));
    } finally {
        aiServerKey('TEME_AI_ANTHROPIC_KEY', null);
    }

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'sk-from-the-server'));
});

it('fails as unavailable without a key, sending nothing (I-314)', function (): void {
    Http::fake();

    expect(fn () => app(AiProviders::class)->find('openai')?->complete(aiRequest('gpt-6-luna')))
        ->toThrow(AiFailure::class, 'AI provider unavailable.');

    Http::assertNothingSent();
});

it('fails by type alone, never carrying the service\'s words (I-314)', function (string $case, string $type, ?int $retryAfter): void {
    aiKey('anthropic_key', 'sk-anthropic-test');
    $words = ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your draft says: the harbour plan']];

    Http::fake(['api.anthropic.com/*' => match ($case) {
        'rate limited' => Http::response($words, 429, ['retry-after' => '20']),
        'out of credit' => Http::response($words, 402),
        'overloaded' => Http::response($words, 529),
        'down' => Http::response($words, 503),
        'broken' => Http::response($words, 500),
        'key refused' => Http::response($words, 401),
        'model gone' => Http::response($words, 404),
        'request refused' => Http::response($words, 400),
        'too large' => Http::response($words, 413),
        'timed out at the gateway' => Http::response($words, 408),
        'too slow' => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'),
        'out of reach' => Http::failedConnection(),
        'declined' => Http::response(aiReply('anthropic', ['finish' => 'refusal'])),
        'cut off' => Http::response(aiReply('anthropic', ['finish' => 'max_tokens'])),
        'empty' => Http::response(aiReply('anthropic', ['text' => '  '])),
        default => Http::response(aiReply('anthropic', ['text' => 'Not the shape asked for.'])),
    }]);
    $schema = $case === 'not the shape asked for' ? ['type' => 'object', 'properties' => ['alt' => ['type' => 'string']], 'required' => ['alt']] : null;
    $caught = null;

    try {
        app(AiProviders::class)->find('anthropic')?->complete(aiRequest('claude-sonnet-5-5', $schema));
    } catch (AiFailure $failure) {
        $caught = $failure;
    }

    expect($caught?->type)->toBe(AiFailureType::from($type))
        ->and($caught?->retryAfterSeconds)->toBe($retryAfter)
        ->and($caught?->getPrevious())->toBeNull()
        ->and((string) $caught?->getMessage())->toStartWith('AI provider')
        ->and((string) $caught?->getMessage())->not->toContain('harbour');
})->with([
    'rate limited, with a wait' => ['rate limited', 'rate-limited', 20],
    'out of credit' => ['out of credit', 'unavailable', null],
    'overloaded' => ['overloaded', 'unavailable', null],
    'down' => ['down', 'unavailable', null],
    'broken' => ['broken', 'unavailable', null],
    'a key it refuses' => ['key refused', 'unavailable', null],
    'a model it no longer has' => ['model gone', 'unavailable', null],
    'a request it refuses' => ['request refused', 'refused', null],
    'a request too large' => ['too large', 'refused', null],
    'a gateway timeout' => ['timed out at the gateway', 'timeout', null],
    'an answer too slow' => ['too slow', 'timeout', null],
    'a host out of reach' => ['out of reach', 'unavailable', null],
    'a refusal to answer' => ['declined', 'refused', null],
    'an answer cut off' => ['cut off', 'invalid-output', null],
    'an empty answer' => ['empty', 'invalid-output', null],
    'an answer not in the shape asked for' => ['not the shape asked for', 'invalid-output', null],
]);
