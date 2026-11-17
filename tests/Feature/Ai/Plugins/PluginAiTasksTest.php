<?php

declare(strict_types=1);

use App\Domain\Ai\Plugins\PluginAiTasks;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\ManifestValidator;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginWords;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);
    $this->errors = fn (array $manifest): array => app(ManifestValidator::class)->errors([...PluginFixtures::manifest('scribe'), ...$manifest], PluginFixtures::path('scribe'), PluginFixtures::composer('scribe'));
    $this->task = fn (array $overrides = []): array => [...['id' => 'article', 'label' => 'Write an article', 'needs' => ['text'], 'sends' => 'brief', 'maxOutputTokens' => 32000], ...$overrides];
    $this->tasks = fn (array ...$tasks): array => ['contributes' => ['aiTasks' => $tasks]];
});

afterEach(fn () => PluginFixtures::forget());

it('accepts the scribe fixture’s two tasks', function (): void {
    expect(($this->errors)([]))->toBe([])
        ->and(array_map(fn ($task): string => $task->id, PluginAiTasks::of(new Manifest(PluginFixtures::manifest('scribe')))))->toBe(['article', 'titles']);
});

it('refuses a declaration outside the rule, each with one plain sentence', function (array $manifest, string $error): void {
    expect(($this->errors)($manifest))->toContain($error);
})->with([
    'more than 8' => [fn () => ($this->tasks)(...array_map(fn (int $i): array => ($this->task)(['id' => "task{$i}"]), range(1, 9))), 'A plugin can declare at most 8 AI tasks.'],
    'a bad id' => [fn () => ($this->tasks)(($this->task)(['id' => 'Bad_Id'])), 'An AI task’s id must be at most 24 lower-case letters and digits, in words joined by hyphens, starting with a letter.'],
    'an id too long' => [fn () => ($this->tasks)(($this->task)(['id' => str_repeat('a', 25)])), 'An AI task’s id must be at most 24 lower-case letters and digits, in words joined by hyphens, starting with a letter.'],
    'two with one id' => [fn () => ($this->tasks)(($this->task)(), ($this->task)()), 'Two AI tasks can’t share the id article.'],
    'an unknown need' => [fn () => ($this->tasks)(($this->task)(['needs' => ['text', 'embeddings']])), 'An AI task needs one or more of text, images-in and structured-output.'],
    'no need' => [fn () => ($this->tasks)(($this->task)(['needs' => []])), 'An AI task needs one or more of text, images-in and structured-output.'],
    'sends unknown' => [fn () => ($this->tasks)(($this->task)(['sends' => 'everything'])), 'An AI task sends brief, draft or published.'],
    'no tokens' => [fn () => ($this->tasks)(($this->task)(['maxOutputTokens' => 0])), 'An AI task’s maxOutputTokens must be a whole number from 1 to 64,000.'],
    'too many tokens' => [fn () => ($this->tasks)(($this->task)(['maxOutputTokens' => 64_001])), 'An AI task’s maxOutputTokens must be a whole number from 1 to 64,000.'],
    'a long label' => [fn () => ($this->tasks)(($this->task)(['label' => str_repeat('x', 41)])), 'An AI task’s label must be 1 to 40 characters.'],
    'no ai' => [['capabilities' => ['jobs']], 'It declares AI tasks, but the plugin doesn’t ask for ai.'],
]);

it('words the consent: the ai sentence naming the tasks, then each task with what it sends', function (): void {
    $tasks = PluginAiTasks::of(new Manifest(PluginFixtures::manifest('scribe')));

    expect(PluginWords::capability('ai', aiTasks: ['Write an article', 'Suggest titles']))
        ->toBe('Use your site’s AI connection (Settings › AI) for: Write an article, Suggest titles. What it sends goes to the AI service chosen there, and it spends your site’s AI budget.')
        ->and(array_map(PluginWords::aiTask(...), $tasks))->toBe([
            'Write an article: sends only what the plugin writes itself',
            'Suggest titles: sends an item’s unpublished text',
        ]);
});

it('keeps the tasks with the consent, and lists them in the Plugins screen’s access lines', function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('scribe');
    PluginFixtures::installed(['tests/scribe' => 'scribe']);
    $manifest = app(PluginRegistry::class)->find('tests/scribe')?->manifest;

    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/scribe', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));

    $row = collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'tests/scribe');

    expect(PluginRecord::query()->where('name', 'tests/scribe')->sole()->consent['aiTasks'])->toEqual([
        'article' => ['sends' => 'brief', 'needs' => ['text']],
        'titles' => ['sends' => 'draft', 'needs' => ['text', 'structured-output']],
    ])
        ->and(array_column($row['agreed'], 'words'))->toContain('Write an article: sends only what the plugin writes itself', 'Suggest titles: sends an item’s unpublished text');
});

it('waits for consent when an update adds a task, sends more or needs more, and not when it narrows one', function (array $tasks, bool $covered, array $added): void {
    $agreed = ['capabilities' => ['ai', 'jobs'], 'hosts' => [], 'routes' => [], 'aiTasks' => [
        'article' => ['sends' => 'published', 'needs' => ['text']],
    ]];
    $manifest = new Manifest([...PluginFixtures::manifest('scribe'), 'contributes' => ['aiTasks' => $tasks]]);

    expect(Consent::covers($agreed, $manifest))->toBe($covered)
        ->and(Consent::added($agreed, $manifest)['aiTasks'] ?? [])->toBe($added);
})->with([
    'the same' => [[['id' => 'article', 'label' => 'Write', 'needs' => ['text'], 'sends' => 'published', 'maxOutputTokens' => 10]], true, []],
    'narrower' => [[['id' => 'article', 'label' => 'Write', 'needs' => ['text'], 'sends' => 'brief', 'maxOutputTokens' => 10]], true, []],
    'a new task' => [[['id' => 'article', 'label' => 'Write', 'needs' => ['text'], 'sends' => 'published', 'maxOutputTokens' => 10], ['id' => 'titles', 'label' => 'Titles', 'needs' => ['text'], 'sends' => 'brief', 'maxOutputTokens' => 10]], false, ['titles']],
    'sends more' => [[['id' => 'article', 'label' => 'Write', 'needs' => ['text'], 'sends' => 'draft', 'maxOutputTokens' => 10]], false, ['article']],
    'needs more' => [[['id' => 'article', 'label' => 'Write', 'needs' => ['text', 'images-in'], 'sends' => 'published', 'maxOutputTokens' => 10]], false, ['article']],
]);
