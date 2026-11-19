<?php

declare(strict_types=1);

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSettingsSection;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\ModelRef;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Queries\AiSettingsScreen;
use App\Domain\Ai\UsageLimits;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->data = fn (): array => app(AiSettingsScreen::class)->data($this->admin);
});

it('draws the services, the models and the limits in the group’s own order (I-1308)', function (): void {
    $data = ($this->data)();

    expect(array_map(fn (array $section): string => $section['kind'].':'.$section['key'], $data['sections']))->toBe([
        'service:anthropic', 'service:openai', 'service:deepseek', 'service:openrouter', 'models:models', 'limits:limits',
    ])
        ->and($data['enabled'])->toBeTrue()
        ->and($data['description'])->toBe('The AI services Teme may use, the model each task uses, and how much people may ask.')
        ->and(aisSection($data, 'models')['label'])->toBe('Models')
        ->and(array_column(aisSection($data, 'limits')['fields'], 'key'))->toBe([UsageLimits::HOURLY, UsageLimits::BUDGET])
        ->and($data['version'])->toBe(0);
});

it('says whether each service’s key is set and by whom, and never sends a key (I-1308)', function (): void {
    aiKey('openai_key', 'sk-openai-never-shown');
    aiServerKey('TEME_AI_DEEPSEEK_KEY', 'sk-deepseek-never-shown');

    try {
        $data = ($this->data)();
    } finally {
        aiServerKey('TEME_AI_DEEPSEEK_KEY', null);
    }

    $states = [];

    foreach ($data['sections'] as $section) {
        if ($section['kind'] === 'service') {
            $states[$section['key']] = [$section['state'], $section['stateLabel']];
        }
    }

    $sent = (string) json_encode($data);

    expect($states)->toBe([
        'anthropic' => ['not-set', 'Not set'],
        'openai' => ['set', 'Set'],
        'deepseek' => ['set-by-server', 'Set by the server'],
        'openrouter' => ['not-set', 'Not set'],
    ])
        ->and($sent)->not->toContain('sk-openai-never-shown')
        ->and($sent)->not->toContain('sk-deepseek-never-shown')
        ->and($data['values'])->not->toHaveKey('openai_key')
        ->and($data['secrets']['openai_key'])->toBe(['isSet' => true, 'source' => 'database']);
});

it('gives each service its other model’s fields with this screen’s hint, and never its key’s field (I-1302)', function (): void {
    $anthropic = aisSection(($this->data)(), 'service', 'anthropic');

    expect($anthropic['label'])->toBe('Anthropic')
        ->and($anthropic['keyField'])->toBe('anthropic_key')
        ->and($anthropic['variable'])->toBe('TEME_AI_ANTHROPIC_KEY')
        ->and(array_column($anthropic['fields'], 'key'))->toBe([
            'anthropic_other_model', 'anthropic_other_abilities', 'anthropic_other_reasons', 'anthropic_other_input_price', 'anthropic_other_output_price',
        ])
        ->and($anthropic['fields'][0]['description'])->toBe('A model Teme’s list doesn’t have yet, written as Anthropic writes it. Once saved, it’s offered under Models for the tasks it can do.');
});

it('offers each task only the models able to do it, from every provider, marking those with no key (I-1308)', function (): void {
    aiFake();
    aiTask('fixture', [AiCapability::Text, AiCapability::ImagesIn]);

    $options = aisTask(($this->data)(), 'fixture')['options'];
    $values = array_column($options, 'value');

    expect($values)->toContain('fake:fake-vision')
        ->and($values)->not->toContain('fake:fake-text')
        ->and($values)->not->toContain('fake:fake-unpriced')
        ->and($values)->not->toContain('fake:fake-embed');

    foreach ($options as $option) {
        $model = app(AiProviders::class)->model(ModelRef::parse($option['value']) ?? throw new LogicException($option['value']));

        expect($model?->can(AiCapability::Text, AiCapability::ImagesIn))->toBeTrue();
    }

    $anthropic = array_values(array_filter($options, fn (array $option): bool => str_starts_with($option['value'], 'anthropic:')));

    expect($anthropic)->not->toBe([])
        ->and(array_values(array_unique(array_column($anthropic, 'ready'))))->toBe([false])
        ->and($options[array_search('fake:fake-vision', $values, true)]['ready'])->toBeTrue();
});

it('names each option by its provider and model, with its price per million tokens and how it reasons', function (): void {
    aiFake();
    aiTask('fixture', [AiCapability::Text]);

    $options = array_column(aisTask(($this->data)(), 'fixture')['options'], null, 'value');

    expect($options['fake:fake-text'])->toBe([
        'value' => 'fake:fake-text',
        'label' => 'Fake AI: Fake text',
        'provider' => 'Fake AI',
        'ready' => true,
        'input' => 1_000_000,
        'output' => 2_000_000,
        'note' => null,
    ])
        ->and($options['fake:fake-unpriced']['input'])->toBeNull()
        ->and($options['fake:fake-unpriced']['output'])->toBeNull();

    $anthropic = array_filter($options, fn (string $value): bool => str_starts_with($value, 'anthropic:'), ARRAY_FILTER_USE_KEY);

    expect($anthropic)->not->toBe([]);

    foreach ($anthropic as $value => $option) {
        expect($option['note'])->toBe(app(AnthropicProvider::class)->reasoning(substr($value, strlen('anthropic:')))->note())
            ->and($option['label'])->toStartWith('Anthropic: ');
    }
});

it('says why a task is off in one reason, and On exactly when it can run (I-321)', function (string $case, string $status): void {
    aiFake()->ready = $case !== 'no key';
    aiTask(
        'fixture',
        $case === 'cannot' ? [AiCapability::Text, AiCapability::ImagesIn] : [AiCapability::Text],
        hash: $case === 'changed prompt' ? str_repeat('0', 64) : null,
    );

    if ($case !== 'no model') {
        aiChoose('fixture', $case === 'gone' ? 'fake:retired' : 'fake:fake-text');
    }

    $task = aisTask(($this->data)(), 'fixture');

    expect($task['status'])->toBe($status)
        ->and($task['on'])->toBe($case === 'on')
        ->and($task['on'])->toBe(app(AiAvailability::class)->taskReady('fixture'));
})->with([
    'a task that can run' => ['on', 'On'],
    'no model chosen' => ['no model', 'Off: no model is chosen.'],
    'a prompt changed without a new version' => ['changed prompt', 'Off: Fixture was changed without a new version number, so Teme won’t run it.'],
    'a model no longer offered' => ['gone', 'Off: the model chosen for it is no longer offered. Choose another model.'],
    'a model that can’t read images' => ['cannot', 'Off: Fake text can’t do everything Fixture needs. Choose another model.'],
    'a provider with no key' => ['no key', 'Off: Fake AI has no key. Set one under AI services.'],
]);

it('gives core’s three tasks their notes and their settings’ names, and another part’s task no note', function (): void {
    $registered = array_map(fn (AiTask $task): string => $task->key(), app(AiTasks::class)->all());

    // The real tasks take these keys once they are built; a fixture stands in only where none has.
    foreach (['rewrite' => [AiCapability::Text], 'alt-text' => [AiCapability::Text, AiCapability::ImagesIn], 'metadata' => [AiCapability::Text, AiCapability::StructuredOutput]] as $key => $capabilities) {
        if (! in_array($key, $registered, true)) {
            aiTask($key, $capabilities, $key === 'alt-text' ? AiSubjectType::Media : AiSubjectType::Content);
        }
    }

    aiTask('fixture');
    $data = ($this->data)();

    expect(aisTask($data, 'rewrite')['note'])->toBe('The Rewrite menu on text you select in the editor.')
        ->and(aisTask($data, 'alt-text')['note'])->toBe('Suggest alt text, in an image’s details. Only models that can read images are offered.')
        ->and(aisTask($data, 'metadata')['note'])->toBe('Suggest metadata, in the editor’s Search and social section.')
        ->and(aisTask($data, 'fixture')['note'])->toBeNull()
        ->and(aisTask($data, 'alt-text')['modelField'])->toBe('alt_text_model')
        ->and(aisTask($data, 'alt-text')['fallbackField'])->toBe('alt_text_fallback');
});

it('says no task is on until one can run', function (): void {
    aiTask('fixture');

    expect(($this->data)()['anyTaskOn'])->toBeFalse();

    aiFake();
    aiChoose('fixture', 'fake:fake-text');

    expect(($this->data)()['anyTaskOn'])->toBeTrue();
});

it('reads this month’s spend, its requests with no price and the budget from the monthly totals (I-320)', function (): void {
    actvRow($this->admin, ['task' => 'fixture']);
    actvRow($this->admin, ['task' => 'fixture', 'model' => 'fake-unpriced', 'costMicrounits' => null]);
    cmSettings([UsageLimits::BUDGET => 50], AiSettings::KEY);

    $month = ($this->data)()['month'];

    expect($month['current'])->toBeTrue()
        ->and($month['costMicrounits'])->toBe(1_400)
        ->and($month['requests'])->toBe(2)
        ->and($month['unpricedRequests'])->toBe(1)
        ->and($month['budgetMicrounits'])->toBe(50_000_000)
        ->and($month['reached'])->toBeFalse();
});

it('names the task asked most this month, by its label, or none when nothing was asked', function (): void {
    aiTask('fixture');

    expect(($this->data)()['mostUsedTask'])->toBeNull();

    actvRow($this->admin, ['task' => 'fixture']);
    actvRow($this->admin, ['task' => 'fixture']);

    expect(($this->data)()['mostUsedTask'])->toBe(app(AiTasks::class)->get('fixture')->label());
});

it('links Tools › AI activity only for whoever may see it', function (): void {
    $role = Role::create(['name' => 'ai-settings-only', 'guard_name' => 'web']);
    $role->givePermissionTo(AiSettings::ABILITY);
    $keeper = User::factory()->create()->assignRole($role);

    expect(app(AiSettingsScreen::class)->data($this->admin)['activityUrl'])->toBe(route('tools.ai-activity.index'))
        ->and(app(AiSettingsScreen::class)->data($keeper)['activityUrl'])->toBeNull();
});

it('draws a section another part registers on the generic controls, in its own place', function (): void {
    app()->instance('test.ai.section.glossary', new readonly class implements AiSettingsSection
    {
        public function order(): int
        {
            return 250;
        }

        public function key(): string
        {
            return 'glossary';
        }

        public function label(): string
        {
            return 'Glossary';
        }

        public function settings(): array
        {
            return [Setting::text('glossary_terms', 'Terms to keep', 'Words a rewrite never changes.', '0.1.0')];
        }
    });
    app()->tag(['test.ai.section.glossary'], AiSettings::SECTIONS);
    app(SettingsRegistry::class)->replace(app(AiSettings::class)->group());

    $data = ($this->data)();

    expect(array_column($data['sections'], 'key'))->toBe(['anthropic', 'openai', 'deepseek', 'openrouter', 'models', 'glossary', 'limits'])
        ->and(aisSection($data, 'other', 'glossary')['label'])->toBe('Glossary')
        ->and(array_column(aisSection($data, 'other', 'glossary')['fields'], 'key'))->toBe(['glossary_terms']);
});

it('gives nothing but the switch while the server has AI switched off (I-1308)', function (): void {
    aiReady();
    config(['teme.ai.enabled' => false]);

    expect(($this->data)())->toBe(['enabled' => false]);
});
