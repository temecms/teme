<?php

declare(strict_types=1);

use App\Domain\Ai\AiSettings;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Ai\UsageLimits;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Ai\AiAsk;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->fake = aiFake();
    scribeEnable();
    scribeChoose('article', 'fake:fake-text');
    scribeRunAs($this->admin);

    $this->save = fn (array $values, ?User $who = null) => $this->actingAs($who ?? $this->admin)->withSession(scConfirmed())
        ->from('/admin/settings/ai')
        ->put('/admin/settings/ai', ['values' => $values, 'expected_version' => 0]);
});

afterEach(fn () => PluginFixtures::forget());

it('gives the Plugins section each plugin’s tasks, models, switch, budget, limit and month', function (): void {
    $this->fake->answer(aiText('An article.'));
    scribeDoor()->ask(new AiAsk('article', 'Write.', ['brief' => 'Ferries']), (string) $this->admin->id);

    $page = $this->actingAs($this->admin)->get('/admin/settings/ai')->assertOk();
    $section = aisSection($page->inertiaProps(), 'plugins');
    $plugin = $section['plugins'][0];

    expect($section['label'])->toBe('Plugins')
        ->and(array_column($section['fields'], 'key'))->toContain(PluginAiSection::onKey('scribe'), PluginAiSection::modelKey('scribe', 'titles'))
        ->and($plugin)->toMatchArray([
            'code' => 'scribe',
            'label' => 'Scribe',
            'onField' => 'plugin_scribe_on',
            'budgetField' => 'plugin_scribe_budget',
            'hourlyField' => 'plugin_scribe_hourly',
            'on' => true,
            'budgetMicrounits' => null,
            'hourly' => 120,
            'month' => ['costMicrounits' => 1_100, 'requests' => 1, 'unpricedRequests' => 0],
        ])
        ->and(array_map(fn (array $task): array => [$task['key'], $task['modelField'], $task['sends'], $task['on'], $task['status']], $plugin['tasks']))->toBe([
            ['article', 'plugin_scribe_article_model', 'brief', true, 'On'],
            ['titles', 'plugin_scribe_titles_model', 'draft', false, 'Off: Suggest titles is off until an administrator chooses its model in Settings › AI.'],
        ])
        // Titles needs structured answers, so the unpriced text-only model isn't offered for it.
        ->and(array_column($plugin['tasks'][1]['options'], 'value'))->toContain('fake:fake-text')->not->toContain('fake:fake-unpriced');
});

it('saves a plugin’s switch, budget, limit and model after the password', function (): void {
    ($this->save)([
        PluginAiSection::onKey('scribe') => false,
        PluginAiSection::budgetKey('scribe') => 4,
        PluginAiSection::hourlyKey('scribe') => 30,
        PluginAiSection::modelKey('scribe', 'titles') => 'fake:fake-vision',
    ])->assertSessionHasNoErrors()->assertRedirect('/admin/settings/ai');

    $section = app(PluginAiSection::class);

    expect($section->on('scribe'))->toBeFalse()
        ->and($section->budgetMicrounits('scribe'))->toBe(4_000_000)
        ->and($section->hourly('scribe'))->toBe(30)
        ->and(app(ModelChoices::class)->forStem('plugin_scribe_titles')?->primary->toString())->toBe('fake:fake-vision');
});

it('refuses a plugin budget above the site’s, and a model that can’t do the task, writing nothing', function (): void {
    cmSettings([UsageLimits::BUDGET => 10], AiSettings::KEY);
    $before = DB::table('settings')->where('group_key', AiSettings::KEY)->value('overrides');

    ($this->save)([PluginAiSection::budgetKey('scribe') => 11])
        ->assertSessionHasErrors(['values.plugin_scribe_budget' => 'Choose a budget no larger than the site’s, 10 US dollars.']);
    ($this->save)([PluginAiSection::modelKey('scribe', 'titles') => 'fake:fake-unpriced'])
        ->assertSessionHasErrors(['values.plugin_scribe_titles_model' => 'Fake unpriced can’t do everything Suggest titles needs.']);

    expect(DB::table('settings')->where('group_key', AiSettings::KEY)->value('overrides'))->toBe($before);
});

it('lets nobody without settings.ai.manage save, writing nothing', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    ($this->save)([PluginAiSection::onKey('scribe') => false], $editor)->assertForbidden();

    expect(app(PluginAiSection::class)->on('scribe'))->toBeTrue();
});

it('filters Tools › AI activity by plugin, names the plugin in each row and totals the month by plugin', function (): void {
    $this->fake->answer(aiText('An article.'));
    scribeDoor()->ask(new AiAsk('article', 'Write.', ['brief' => 'Ferries']), (string) $this->admin->id);
    aiTask('tidy');
    $core = actvRow($this->admin);

    $screen = fn (array $query = []) => $this->actingAs($this->admin)->get(route('tools.ai-activity.index', $query));

    $screen()->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('rows', 2)
        ->where('plugins', [['value' => 'teme', 'label' => 'Teme'], ['value' => 'scribe', 'label' => 'Scribe']])
        ->where('pluginMonths', [['code' => 'scribe', 'label' => 'Scribe', 'costMicrounits' => 1_100, 'requests' => 1, 'unpricedRequests' => 0]]));

    $screen(['plugin' => 'scribe'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('rows', 1)
        ->where('filters.plugin', 'scribe')
        ->where('rows.0.plugin', ['code' => 'scribe', 'label' => 'Scribe'])
        ->where('rows.0.task.label', 'Write an article')
        ->where('rows.0.subject.state', 'none'));

    $screen(['plugin' => 'teme'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('rows', 1)
        ->where('rows.0.id', $core)
        ->where('rows.0.plugin', null));
});

it('carries this month’s AI cost and budget on the Plugins screen’s row', function (): void {
    cmSettings([PluginAiSection::budgetKey('scribe') => 5], AiSettings::KEY);
    DB::table('ai_plugin_monthly_spend')->insert(['plugin' => 'scribe', 'month' => now()->utc()->startOfMonth()->format('Y-m-d'), 'cost_microunits' => 4_200_000, 'requests' => 3, 'unpriced_requests' => 0]);

    $row = collect(app(PluginList::class)->all())->firstWhere('name', 'tests/scribe');
    $screen = collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/scribe');

    expect($row->ai)->toBe(['costMicrounits' => 4_200_000, 'budgetMicrounits' => 5_000_000])
        ->and($screen['ai'])->toBe(['costMicrounits' => 4_200_000, 'budgetMicrounits' => 5_000_000])
        ->and(collect(app(PluginList::class)->all())->every(fn ($other): bool => $other->name === 'tests/scribe' || $other->ai === null))->toBeTrue();
});
