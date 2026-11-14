<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Ai;
use Laravel\Ai\AnonymousAgent;
use Symfony\Component\Finder\SplFileInfo;

uses(RefreshDatabase::class);

it('sets a provider up while the site runs, with a key the site gives it, sent as the service expects (I-311)', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => 'Hello.']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
    ])]);

    $provider = Ai::build(['name' => 'teme-proof', 'driver' => 'anthropic', 'key' => 'sk-ant-given-at-runtime']);
    $response = (new AnonymousAgent('Be brief.', [], []))->prompt('Say hello.', provider: $provider->name(), model: 'claude-haiku-5-5', timeout: 30);

    expect($response->text)->toBe('Hello.');

    Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'api.anthropic.com'
        && $request->hasHeader('x-api-key', 'sk-ant-given-at-runtime'));
});

it('never creates or ships the library\'s conversation tables (I-311)', function (): void {
    $shipped = array_filter(File::files(database_path('migrations')), fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'agent_conversation'));

    expect(Schema::hasTable('agent_conversations'))->toBeFalse()
        ->and(Schema::hasTable('agent_conversation_messages'))->toBeFalse()
        ->and($shipped)->toBe([])
        ->and(file_exists(config_path('ai.php')))->toBeFalse();
});
