<?php

declare(strict_types=1);

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSettingsSection;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\ModelChoice;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\ModelRef;
use App\Domain\Ai\UsageLimits;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');

    // A section as another part registers one, under the group's tag.
    $this->section = function (string $key, int $order): void {
        app()->instance("test.ai.section.{$key}", new readonly class($key, $order) implements AiSettingsSection
        {
            public function __construct(private string $key, private int $order) {}

            public function order(): int
            {
                return $this->order;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function settings(): array
            {
                return [Setting::boolean("{$this->key}_switch", 'A switch', 'A switch for this test.', '0.1.0')];
            }
        });
        app()->tag(["test.ai.section.{$key}"], AiSettings::SECTIONS);
    };

    // The group is built at boot; what a test adds afterwards needs it built again.
    $this->rebuild = fn () => app(SettingsRegistry::class)->replace(app(AiSettings::class)->group());
});

it('registers the ai group for administrators, asking for the password again, with its Models and Limits sections (I-315)', function (): void {
    $group = app(SettingsRegistry::class)->find('ai');
    $ours = array_values(array_filter(app(AiSettings::class)->ordered(), fn (AiSettingsSection $section): bool => in_array($section->key(), ['models', 'limits'], true)));

    expect($group?->label)->toBe('AI')
        ->and($group?->description)->toBe('The AI services Teme may use, the model each task uses, and how much people may ask.')
        ->and($group?->ability)->toBe('settings.ai.manage')
        ->and($group?->sensitive)->toBeTrue()
        ->and($group?->cacheTags)->toBe([])
        ->and(array_map(fn (AiSettingsSection $section): array => [$section->order(), $section->key(), $section->label()], $ours))
        ->toBe([[200, 'models', 'Models'], [300, 'limits', 'Limits']])
        ->and($group?->setting('hourly_limit')?->label)->toBe('Requests an hour, per person')
        ->and($group?->setting('monthly_budget')?->description)->toBe('When this month’s recorded cost reaches it, AI is unavailable to everyone until the month ends. Requests already running may pass it a little. 0 means no budget.')
        ->and(UsageLimits::hourly())->toBe(60)
        ->and(UsageLimits::budgetMicrounits())->toBeNull();
});

it('gives every registered task a model and a fallback, named from its key (I-315)', function (): void {
    aiTask('alt-text-like', [AiCapability::Text, AiCapability::ImagesIn], AiSubjectType::Media);
    $group = app(SettingsRegistry::class)->find('ai');

    expect(ModelChoices::modelKey('alt-text-like'))->toBe('alt_text_like_model')
        ->and(ModelChoices::fallbackKey('alt-text-like'))->toBe('alt_text_like_fallback')
        ->and($group?->setting('alt_text_like_model')?->label)->toBe('Model for Alt text like')
        ->and($group?->setting('alt_text_like_model')?->description)->toBe('The provider and model that answer, written provider:model. Empty switches Alt text like off.')
        ->and($group?->setting('alt_text_like_fallback')?->label)->toBe('Fallback for Alt text like')
        ->and($group?->setting('alt_text_like_fallback')?->description)->toBe('Asked once when the first model can’t be reached or is busy, written provider:model. Optional.')
        ->and($group?->setting('alt_text_like_model')?->default)->toBe('')
        ->and(app(ModelChoices::class)->for('alt-text-like'))->toBeNull();

    aiChoose('alt-text-like', 'fake:fake-vision');

    expect(app(ModelChoices::class)->for('alt-text-like'))->toEqual(new ModelChoice(new ModelRef('fake', 'fake-vision')));
});

it('orders sections by their own order, wherever they were registered, and the group\'s settings with them', function (): void {
    ($this->section)('extra', 900);
    ($this->section)('keys', 155);
    ($this->rebuild)();

    $keys = array_map(fn (AiSettingsSection $section): string => $section->key(), app(AiSettings::class)->ordered());
    $settings = array_map(fn (Setting $setting): string => $setting->key, app(SettingsRegistry::class)->find('ai')?->settings ?? []);

    expect(array_values(array_intersect($keys, ['keys', 'models', 'limits', 'extra'])))->toBe(['keys', 'models', 'limits', 'extra'])
        ->and(array_values(array_intersect($settings, ['keys_switch', 'hourly_limit', 'extra_switch'])))->toBe(['keys_switch', 'hourly_limit', 'extra_switch']);
});

it('refuses two sections with one order or one key, naming them', function (string $key, int $order, string $message): void {
    ($this->section)($key, $order);

    expect($this->rebuild)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'one order twice' => ['clash', 200, 'AI settings sections [models] and [clash] share the order 200.'],
    'one key twice' => ['limits', 400, 'Two AI settings sections have the key [limits].'],
]);

it('lets administrators change it once they confirm their password, and nobody else (I-312)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($this->admin)->get('/admin/settings/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('enabled', true)
            ->where('values.hourly_limit', 60)
            ->where('values.monthly_budget', 0));

    $this->actingAs($this->admin)
        ->put('/admin/settings/ai', ['values' => ['hourly_limit' => 30], 'expected_version' => 0])
        ->assertRedirect(route('password.confirm'));

    expect(UsageLimits::hourly())->toBe(60);

    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->put('/admin/settings/ai', ['values' => ['hourly_limit' => 30, 'monthly_budget' => 25], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/ai');

    expect(UsageLimits::hourly())->toBe(30)
        ->and(UsageLimits::budgetMicrounits())->toBe(25_000_000);

    $this->actingAs($editor)->get('/admin/settings/ai')->assertForbidden();
});

it('checks a chosen model against the providers, their models and what the task needs (I-315)', function (string $case, string $field, string $message): void {
    aiFake();
    aiTask('fixture', [AiCapability::Text, AiCapability::ImagesIn]);

    $values = match ($case) {
        'malformed' => ['fixture_model' => 'fake-vision'],
        'unknown provider' => ['fixture_model' => 'nobody:fake-vision'],
        'unknown model' => ['fixture_model' => 'fake:retired'],
        'not able' => ['fixture_model' => 'fake:fake-text'],
        'fallback alone' => ['fixture_fallback' => 'fake:fake-vision'],
        default => ['fixture_model' => 'fake:fake-vision', 'fixture_fallback' => ' fake:fake-vision'],
    };

    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->put('/admin/settings/ai', ['values' => $values, 'expected_version' => 0])
        ->assertSessionHasErrors(["values.{$field}" => $message]);

    expect(app(ModelChoices::class)->for('fixture'))->toBeNull();
})->with([
    'not written provider:model' => ['malformed', 'fixture_model', 'Write the model as provider:model.'],
    'a provider Teme doesn\'t have' => ['unknown provider', 'fixture_model', 'Teme has no AI provider called nobody.'],
    'a model the provider doesn\'t list' => ['unknown model', 'fixture_model', 'Fake AI has no model called retired.'],
    'a model that can\'t read images' => ['not able', 'fixture_model', 'Fake text can’t do everything Fixture needs.'],
    'a fallback without a model' => ['fallback alone', 'fixture_fallback', 'Choose a model before its fallback.'],
    'a fallback that is the model' => ['same', 'fixture_fallback', 'Choose a fallback that differs from the model.'],
]);

it('saves a model and a fallback that can do what the task needs', function (): void {
    aiFake();
    aiTask('fixture', [AiCapability::Text]);

    $this->actingAs($this->admin)->withSession(scConfirmed())
        ->put('/admin/settings/ai', ['values' => ['fixture_model' => ' fake:fake-text ', 'fixture_fallback' => 'fake:fake-unpriced'], 'expected_version' => 0])
        ->assertSessionHasNoErrors();

    expect(app(ModelChoices::class)->for('fixture'))->toEqual(new ModelChoice(new ModelRef('fake', 'fake-text'), new ModelRef('fake', 'fake-unpriced')));
});

it('reads TEME_AI as on when unset or a yes, and off when a no or any other word (I-315)', function (?string $raw, bool $on): void {
    $set = function (?string $value): void {
        if ($value === null) {
            putenv('TEME_AI');
            unset($_ENV['TEME_AI'], $_SERVER['TEME_AI']);

            return;
        }

        putenv("TEME_AI={$value}");
        $_ENV['TEME_AI'] = $value;
        $_SERVER['TEME_AI'] = $value;
    };

    try {
        $set($raw);

        expect((require config_path('teme.php'))['ai']['enabled'])->toBe($on);
    } finally {
        $set(null);
    }
})->with([
    'unset' => [null, true],
    'empty' => ['', true],
    'on' => ['on', true],
    'off' => ['off', false],
    'false' => ['false', false],
    'a word it doesn\'t know' => ['disabled', false],
]);

it('says in the group\'s description that AI is switched off, while the server says so (I-315)', function (): void {
    config(['teme.ai.enabled' => false]);
    ($this->rebuild)();

    expect(app(SettingsRegistry::class)->find('ai')?->description)
        ->toBe('The AI services Teme may use, the model each task uses, and how much people may ask. AI is switched off on this server (TEME_AI=off), so none of this takes effect until it’s switched on.');
});

it('limits each person to the hourly limit, keyed by who they are (I-320)', function (): void {
    cmSettings([UsageLimits::HOURLY => 12], AiSettings::KEY);
    $request = Request::create('/admin');
    $request->setUserResolver(fn (): User => $this->admin);

    $limit = RateLimiter::limiter('ai')($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(12)
        ->and($limit->decaySeconds)->toBe(3600)
        ->and($limit->key)->toBe('ai:'.$this->admin->id);
});
