<?php

declare(strict_types=1);

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiImage;
use App\Domain\Ai\AiMessage;
use App\Domain\Ai\AiModel;
use App\Domain\Ai\AiPrice;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\ModelRef;

it('refuses a request it could not send as given', function (string $case, string $message): void {
    $hello = [AiMessage::user('Hello')];

    $make = match ($case) {
        'no messages' => fn () => new AiRequest('m', [], 100, 30),
        'system second' => fn () => new AiRequest('m', [AiMessage::user('Hello'), AiMessage::system('Be brief.')], 100, 30),
        'no model' => fn () => new AiRequest(' ', $hello, 100, 30),
        'no output' => fn () => new AiRequest('m', $hello, 0, 30),
        'too long' => fn () => new AiRequest('m', $hello, 100, 56),
        'a list schema' => fn () => new AiRequest('m', $hello, 100, 30, schema: ['type' => 'array']),
        'an svg' => fn () => new AiImage('image/svg+xml', '<svg/>'),
        default => fn () => new AiImage('image/png', str_repeat('x', AiImage::MAX_BYTES + 1)),
    };

    expect($make)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no messages' => ['no messages', 'An AI request needs at least one message, the system message first if there is one.'],
    'a system message second' => ['system second', 'An AI request needs at least one message, the system message first if there is one.'],
    'a model without a name' => ['no model', 'An AI request\'s model needs a name.'],
    'no room to answer' => ['no output', 'An AI request\'s output limit [0] must be between 1 and 64000 tokens.'],
    'a timeout past the worker\'s' => ['too long', 'An AI request\'s timeout [56] must be between 1 and 55 seconds.'],
    'a schema that isn\'t an object' => ['a list schema', 'An AI request\'s schema must describe an object.'],
    'an SVG' => ['an svg', 'An AI image must be JPEG, PNG, WebP or GIF, not [image/svg+xml].'],
    'an image over 4 MB' => ['too large', 'An AI image is larger than 4 MB.'],
]);

it('carries a well-formed request as given: its model, messages, images and schema', function (): void {
    $image = new AiImage('image/webp', 'RIFF-WEBP-bytes');
    $schema = ['type' => 'object', 'properties' => ['alt' => ['type' => 'string']], 'required' => ['alt']];
    $request = new AiRequest('vision-1', [AiMessage::system('Describe the image.'), AiMessage::user('<input name="title">Harbour</input>')], 200, 45, [$image], $schema);

    expect($request->model)->toBe('vision-1')
        ->and(array_map(fn (AiMessage $message): string => $message->role, $request->messages))->toBe(['system', 'user'])
        ->and($request->maxOutputTokens)->toBe(200)
        ->and($request->timeoutSeconds)->toBe(45)
        ->and($request->images)->toBe([$image])
        ->and($image->base64())->toBe(base64_encode('RIFF-WEBP-bytes'))
        ->and($request->schema)->toBe($schema);
});

it('answers with text or data, never both or neither, its counts never below zero', function (): void {
    $usage = new AiUsage(input: 10, output: 5);

    expect((new AiResult('m', $usage, 120, text: 'Shorter.'))->text)->toBe('Shorter.')
        ->and((new AiResult('m', $usage, 120, data: ['alt' => 'A ferry at dawn']))->data)->toBe(['alt' => 'A ferry at dawn'])
        ->and(fn () => new AiResult('m', $usage, 120))->toThrow(InvalidArgumentException::class, 'An AI result carries text or data, never both or neither.')
        ->and(fn () => new AiResult('m', $usage, 120, 'Shorter.', ['alt' => 'A ferry']))->toThrow(InvalidArgumentException::class, 'An AI result carries text or data, never both or neither.')
        ->and(fn () => new AiUsage(input: -1))->toThrow(InvalidArgumentException::class, 'AI usage counts can\'t be negative.')
        ->and(fn () => new AiResult('m', $usage, -1, text: 'Shorter.'))->toThrow(InvalidArgumentException::class, 'AI usage counts can\'t be negative.');
});

it('fails in five types, each with Teme\'s own words, falling back only when the service couldn\'t answer (I-314)', function (string $type, string $sentence, bool $fallsBack): void {
    $failure = match ($type) {
        'unavailable' => AiFailure::unavailable(),
        'rate-limited' => AiFailure::rateLimited(20),
        'refused' => AiFailure::refused(),
        'invalid-output' => AiFailure::invalidOutput(),
        default => AiFailure::timedOut(),
    };

    expect($failure->type)->toBe(AiFailureType::from($type))
        ->and($failure->type->sentence())->toBe($sentence)
        ->and($failure->type->fallsBack())->toBe($fallsBack)
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->getMessage())->toStartWith('AI provider')
        ->and($failure->retryAfterSeconds)->toBe($type === 'rate-limited' ? 20 : null);
})->with([
    'unavailable' => ['unavailable', 'The AI service couldn’t be reached.', true],
    'rate-limited' => ['rate-limited', 'The AI service is busy and asked Teme to wait.', true],
    'refused' => ['refused', 'The AI service declined to answer.', false],
    'invalid output' => ['invalid-output', 'The AI service’s answer couldn’t be used.', false],
    'a timeout' => ['timeout', 'The AI service took too long to answer.', true],
]);

it('can only be made by its five constructors, so no provider\'s words and no earlier exception ride along (I-314)', function (): void {
    $constructor = (new ReflectionClass(AiFailure::class))->getConstructor();

    expect($constructor?->isPrivate())->toBeTrue()
        ->and(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor?->getParameters() ?? []))
        ->toBe(['type', 'retryAfterSeconds', 'usage']);
});

it('costs a request in whole micro-dollars, rounded up, a cache price left out costing as input (I-1303)', function (): void {
    // $3 and $15 a million tokens; cache reads $0.30; cache writes left out.
    $price = new AiPrice(input: 3_000_000, output: 15_000_000, cacheRead: 300_000);

    expect($price->cost(new AiUsage(input: 1_000, output: 500)))->toBe(3_000 + 7_500)
        ->and($price->cost(new AiUsage(input: 1)))->toBe(3)
        ->and($price->cost(new AiUsage(cacheRead: 1)))->toBe(1)
        ->and($price->cost(new AiUsage(cacheWrite: 1_000)))->toBe(3_000)
        ->and($price->cost(new AiUsage))->toBe(0)
        ->and(fn () => new AiPrice(-1, 0))->toThrow(InvalidArgumentException::class, 'An AI price can\'t be negative.');
});

it('says what a model can do, from the seven capabilities a model may declare (I-887)', function (): void {
    $model = new AiModel('vision-1', 'Vision 1', [AiCapability::Text, AiCapability::ImagesIn]);

    expect($model->can(AiCapability::Text))->toBeTrue()
        ->and($model->can(AiCapability::Text, AiCapability::ImagesIn))->toBeTrue()
        ->and($model->can(AiCapability::Text, AiCapability::StructuredOutput))->toBeFalse()
        ->and($model->can())->toBeTrue()
        ->and(fn () => new AiModel('bare', 'Bare', []))->toThrow(InvalidArgumentException::class, 'AI model [bare] needs a capability.')
        ->and(array_map(fn (AiCapability $capability): string => $capability->value, AiCapability::cases()))
        ->toBe(['text', 'images-in', 'structured-output', 'embeddings', 'rerank', 'images-out', 'decide']);
});

it('reads a model choice as provider:model, the model keeping its own slashes and colons', function (string $value, ?string $expected): void {
    $ref = ModelRef::parse($value);

    expect($ref === null ? null : $ref->provider.' | '.$ref->model)->toBe($expected)
        ->and($ref === null ? null : ModelRef::parse($ref->toString()))->toEqual($ref);
})->with([
    'a plain model' => ['anthropic:claude-sonnet', 'anthropic | claude-sonnet'],
    'a routed model' => ['openrouter:meta-llama/llama-3.1-8b-instruct:free', 'openrouter | meta-llama/llama-3.1-8b-instruct:free'],
    'spaces around it' => ['  fake:fake-text  ', 'fake | fake-text'],
    'no provider' => [':claude-sonnet', null],
    'no model' => ['anthropic:', null],
    'no colon' => ['claude-sonnet', null],
]);
