<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('asks the provider whether the key works, and says so in one sentence, never showing the key (I-315)', function (string $case, string $result, string $sentence): void {
    aiKey('anthropic_key', 'sk-anthropic-never-shown');
    Http::fake(['api.anthropic.com/*' => match ($case) {
        'works' => Http::response(['data' => []]),
        'refused' => Http::response(['error' => ['message' => 'invalid x-api-key']], 401),
        'forbidden' => Http::response(['error' => ['message' => 'not allowed']], 403),
        'failing' => Http::response(['error' => ['message' => 'overloaded']], 503),
        default => Http::failedConnection(),
    }]);

    $response = $this->actingAs($this->admin)->postJson('/admin/settings/ai/anthropic/check-key')
        ->assertOk()
        ->assertExactJson(['result' => $result, 'sentence' => $sentence, 'state' => 'set', 'stateLabel' => 'Set']);

    expect((string) $response->getContent())->not->toContain('sk-anthropic-never-shown')
        ->and((string) $response->getContent())->not->toContain('invalid x-api-key');
})->with([
    'a key that works' => ['works', 'works', 'The key works.'],
    'a key refused' => ['refused', 'refused', 'Anthropic didn’t accept this key.'],
    'a key without the right' => ['forbidden', 'refused', 'Anthropic didn’t accept this key.'],
    'a service failing' => ['failing', 'unreachable', 'Anthropic couldn’t be reached to check the key. Try again in a minute.'],
    'a service out of reach' => ['offline', 'unreachable', 'Anthropic couldn’t be reached to check the key. Try again in a minute.'],
]);

it('says there is no key to check, asking no one (I-315)', function (): void {
    Http::fake();

    $this->actingAs($this->admin)->postJson('/admin/settings/ai/openai/check-key')
        ->assertOk()
        ->assertExactJson(['result' => 'missing', 'sentence' => 'There’s no key to check.', 'state' => 'not-set', 'stateLabel' => 'Not set']);

    Http::assertNothingSent();
});

it('asks each provider at a free address, with the key as that provider takes it (I-315)', function (string $provider, string $url, string $header, string $value): void {
    aiKey("{$provider}_key", 'sk-test');
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->actingAs($this->admin)->postJson("/admin/settings/ai/{$provider}/check-key")->assertOk()->assertJsonPath('result', 'works');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $url && $request->hasHeader($header, $value));
})->with([
    'Anthropic' => ['anthropic', 'https://api.anthropic.com/v1/models', 'x-api-key', 'sk-test'],
    'OpenAI' => ['openai', 'https://api.openai.com/v1/models', 'Authorization', 'Bearer sk-test'],
    'DeepSeek' => ['deepseek', 'https://api.deepseek.com/models', 'Authorization', 'Bearer sk-test'],
    'OpenRouter' => ['openrouter', 'https://openrouter.ai/api/v1/key', 'Authorization', 'Bearer sk-test'],
]);

it('lets only those who manage AI check a key, asking no one for anyone else (I-312)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    aiKey('anthropic_key', 'sk-anthropic-test');
    Http::fake();

    $this->actingAs($editor)->postJson('/admin/settings/ai/anthropic/check-key')->assertForbidden();

    Http::assertNothingSent();
});

it('knows no provider by a key it hasn\'t', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/settings/ai/fal/check-key')->assertNotFound();
});

it('checks no key while the server has switched AI off, contacting no one (I-315)', function (): void {
    config(['teme.ai.enabled' => false]);
    aiKey('anthropic_key', 'sk-anthropic-test');
    Http::fake();

    $this->actingAs($this->admin)->postJson('/admin/settings/ai/anthropic/check-key')->assertForbidden();

    Http::assertNothingSent();
});
