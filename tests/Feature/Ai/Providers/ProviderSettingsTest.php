<?php

declare(strict_types=1);

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiPrice;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSettingsSection;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\ModelRef;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\DeepSeekProvider;
use App\Domain\Ai\Providers\OpenRouterProvider;
use App\Domain\Ai\Providers\Reasoning;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('registers four providers, each with its section of Settings › AI in its own place (I-315)', function (): void {
    $ours = ['anthropic', 'openai', 'deepseek', 'openrouter'];
    $providers = array_map(fn (AiProvider $provider): string => $provider->key(), app(AiProviders::class)->all());
    $sections = array_values(array_filter(
        array_map(fn (AiSettingsSection $section): array => [$section->order(), $section->key(), $section->label()], app(AiSettings::class)->ordered()),
        fn (array $section): bool => in_array($section[1], $ours, true),
    ));

    expect(array_values(array_intersect($providers, $ours)))->toBe($ours)
        ->and($sections)->toBe([[100, 'anthropic', 'Anthropic'], [110, 'openai', 'OpenAI'], [120, 'deepseek', 'DeepSeek'], [130, 'openrouter', 'OpenRouter']]);
});

it('keeps each provider\'s key as a secret the server may set instead, saying where text goes (I-1304)', function (string $provider, string $label, string $variable): void {
    $setting = app(SettingsRegistry::class)->find('ai')?->setting("{$provider}_key");

    expect($setting?->type)->toBe(SettingType::Secret)
        ->and($setting?->label)->toBe("{$label} API key")
        ->and($setting?->env)->toBe($variable)
        ->and($setting?->description)->toBe("Text you ask AI about is sent to {$label}. The key is kept encrypted and never shown again. The server can set it instead, as {$variable}.");
})->with([
    'Anthropic' => ['anthropic', 'Anthropic', 'TEME_AI_ANTHROPIC_KEY'],
    'OpenAI' => ['openai', 'OpenAI', 'TEME_AI_OPENAI_KEY'],
    'DeepSeek' => ['deepseek', 'DeepSeek', 'TEME_AI_DEEPSEEK_KEY'],
    'OpenRouter' => ['openrouter', 'OpenRouter', 'TEME_AI_OPENROUTER_KEY'],
]);

it('says whether a key is set, and by whom, and is ready to answer only with one (I-1308)', function (string $case, string $state, string $label, bool $ready): void {
    try {
        if ($case === 'stored') {
            aiKey('anthropic_key', 'sk-anthropic-test');
        }

        if ($case === 'from the server') {
            aiServerKey('TEME_AI_ANTHROPIC_KEY', 'sk-from-the-server');
        }

        $provider = app(AnthropicProvider::class);

        expect($provider->keyState()->value)->toBe($state)
            ->and($provider->keyState()->label())->toBe($label)
            ->and($provider->ready())->toBe($ready);
    } finally {
        aiServerKey('TEME_AI_ANTHROPIC_KEY', null);
    }
})->with([
    'no key' => ['none', 'not-set', 'Not set', false],
    'a stored key' => ['stored', 'set', 'Set', true],
    'a key from the server' => ['from the server', 'set-by-server', 'Set by the server', true],
]);

it('shows on the settings page whether each key is set, and never the key (I-315)', function (): void {
    aiKey('openai_key', 'sk-openai-never-shown');

    $response = $this->actingAs($this->admin)->get('/admin/settings/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('secrets.openai_key.isSet', true)
            ->where('secrets.openai_key.source', 'database')
            ->where('secrets.anthropic_key.isSet', false));

    expect((string) $response->getContent())->not->toContain('sk-openai-never-shown');
});

it('offers another model an administrator names, with what it can do, its price and whether it reasons, chosen under Models once saved (I-1302)', function (): void {
    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->put('/admin/settings/ai', ['values' => [
            'openrouter_other_model' => 'mistralai/mistral-large-3:nitro',
            'openrouter_other_abilities' => ['text', 'structured-output'],
            'openrouter_other_reasons' => true,
            'openrouter_other_input_price' => '2',
            'openrouter_other_output_price' => '6.5',
        ], 'expected_version' => 0])
        ->assertSessionHasNoErrors();

    $model = app(AiProviders::class)->model(new ModelRef('openrouter', 'mistralai/mistral-large-3:nitro'));

    expect($model?->label)->toBe('mistralai/mistral-large-3:nitro')
        ->and($model?->capabilities)->toBe([AiCapability::Text, AiCapability::StructuredOutput])
        ->and($model?->price)->toEqual(new AiPrice(2_000_000, 6_500_000))
        ->and(app(OpenRouterProvider::class)->reasoning('mistralai/mistral-large-3:nitro'))->toBe(Reasoning::Lowest);

    aiTask('fixture', [AiCapability::Text]);

    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->put('/admin/settings/ai', ['values' => ['fixture_model' => 'openrouter:mistralai/mistral-large-3:nitro'], 'expected_version' => 1])
        ->assertSessionHasNoErrors();

    expect(app(ModelChoices::class)->for('fixture')?->primary)->toEqual(new ModelRef('openrouter', 'mistralai/mistral-large-3:nitro'));
});

it('records another model\'s cost as unknown when its prices are left empty (I-1302)', function (): void {
    cmSettings(['deepseek_other_model' => 'deepseek-next', 'deepseek_other_abilities' => ['text']], AiSettings::KEY);

    expect(app(AiProviders::class)->model(new ModelRef('deepseek', 'deepseek-next'))?->price)->toBeNull()
        ->and(app(AiProviders::class)->cost(new ModelRef('deepseek', 'deepseek-next'), new AiUsage(input: 1_000)))->toBeNull()
        ->and(app(DeepSeekProvider::class)->reasoning('deepseek-next'))->toBe(Reasoning::None);
});

it('refuses another model it couldn\'t ask or price, under the field to fix (I-1302)', function (array $values, string $field, string $message): void {
    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->put('/admin/settings/ai', ['values' => $values, 'expected_version' => 0])
        ->assertSessionHasErrors(["values.{$field}" => $message]);
})->with([
    'a name with a space' => [['anthropic_other_model' => 'claude next', 'anthropic_other_abilities' => ['text']], 'anthropic_other_model', 'Write the model’s name as Anthropic writes it, without spaces.'],
    'a name the list has' => [['anthropic_other_model' => 'claude-haiku-5-5', 'anthropic_other_abilities' => ['text']], 'anthropic_other_model', 'Anthropic’s list already has claude-haiku-5-5; choose it under Models.'],
    'nothing it can do' => [['openai_other_model' => 'gpt-next'], 'openai_other_abilities', 'Choose what this model can do.'],
    'a price that isn\'t one' => [['openai_other_model' => 'gpt-next', 'openai_other_abilities' => ['text'], 'openai_other_input_price' => '$3', 'openai_other_output_price' => '15'], 'openai_other_input_price', 'Write a price in US dollars, such as 3 or 0.25.'],
    'one price alone' => [['openai_other_model' => 'gpt-next', 'openai_other_abilities' => ['text'], 'openai_other_input_price' => '3'], 'openai_other_output_price', 'Write both prices, or neither.'],
]);
