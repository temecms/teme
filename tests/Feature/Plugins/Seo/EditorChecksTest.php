<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Editor\CheckedItem;
use Teme\Contracts\Editor\CheckResult;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Seokit\Seokit;

require_once __DIR__.'/Seokit.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->ask = fn (User $viewer, string $content, ?string $revision) => $this->actingAs($viewer)
        ->getJson(route('content.plugin-checks', ['content' => $content, ...($revision === null ? [] : ['revision' => $revision])]));
});

afterEach(function (): void {
    Seokit::reset();
    PluginFixtures::forget();
});

it('asks the plugin about the revision being edited and draws its results under its name', function (): void {
    seokitEnabled();
    $draft = lifecycleDraft($this->author, 'Harbour news');
    Seokit::$checks = fn (CheckedItem $item): array => [
        new CheckResult('keyphrase', CheckResult::IMPROVE, 'Add a focus keyphrase.', ownField: 'focus'),
        new CheckResult('length', CheckResult::OK, "{$item->title} is long enough.", label: 'Length', field: 'excerpt'),
    ];

    ($this->ask)($this->author, $draft->id, $draft->working_revision_id)->assertOk()->assertExactJson(['plugins' => [[
        'code' => 'seokit',
        'plugin' => 'SEO Kit',
        'checks' => [
            ['id' => 'keyphrase', 'label' => 'Focus keyphrase', 'state' => 'improve', 'message' => 'Add a focus keyphrase.', 'field' => null, 'ownField' => 'focus'],
            ['id' => 'length', 'label' => 'Length', 'state' => 'ok', 'message' => 'Harbour news is long enough.', 'field' => 'excerpt', 'ownField' => null],
        ],
        'failed' => null,
    ]]]);

    $item = Seokit::$checked[0];

    expect($item->itemId)->toBe($draft->id)
        ->and($item->revisionId)->toBe($draft->working_revision_id)
        ->and($item->type)->toBe('post')
        ->and($item->title)->toBe('Harbour news')
        ->and($item->personId)->toBe((string) $this->author->id)
        ->and($item->own)->toBe([]);
});

it('says the checks couldn’t run, and records the fault, when the plugin throws, is too slow or answers out of bounds', function (): void {
    ['faults' => $faults, 'clock' => $clock] = seokitEnabled();
    $draft = lifecycleDraft($this->author, 'Harbour news');
    $failed = ['code' => 'seokit', 'plugin' => 'SEO Kit', 'checks' => [], 'failed' => 'These checks couldn’t run this time.'];

    Seokit::$checks = fn (): array => throw new RuntimeException('Broken');
    ($this->ask)($this->author, $draft->id, $draft->working_revision_id)->assertOk()->assertExactJson(['plugins' => [$failed]]);

    Seokit::$checks = fn (): array => [new CheckResult('keyphrase', CheckResult::OK, 'Fine.')];
    $clock->steps = [0, 300_000_000];
    ($this->ask)($this->author, $draft->id, $draft->working_revision_id)->assertOk()->assertExactJson(['plugins' => [$failed]]);

    Seokit::$checks = fn (): array => [new CheckResult('elsewhere', CheckResult::OK, 'Fine.'), 'not a result'];
    ($this->ask)($this->author, $draft->id, $draft->working_revision_id)->assertOk()->assertJsonPath('plugins.0.checks', []);

    expect(array_map(fn (array $fault): string => $fault[1], $faults->seen))->toBe([
        'tests/seokit#editor-checks', 'tests/seokit#editor-checks', 'tests/seokit#editor-checks', 'tests/seokit#editor-checks',
    ]);
});

it('refuses someone who may not edit the item, and needs the revision named', function (): void {
    seokitEnabled();
    $draft = lifecycleDraft($this->author, 'Harbour news');
    $other = User::factory()->create()->assignRole('author');

    ($this->ask)($other, $draft->id, $draft->working_revision_id)->assertForbidden();
    ($this->ask)($this->author, $draft->id, null)->assertNotFound();

    expect(Seokit::$checked)->toBe([]);
});

it('keeps to the checks declared for the item’s type, and asks no plugin that isn’t there', function (): void {
    seokitEnabled();
    $editor = User::factory()->create()->assignRole('editor');
    $page = lifecycleDraft($editor, 'About', 'page');
    Seokit::$checks = fn (): array => [new CheckResult('length', CheckResult::OK, 'Fine.'), new CheckResult('keyphrase', CheckResult::OK, 'Fine.')];

    ($this->ask)($editor, $page->id, $page->working_revision_id)->assertOk()->assertJsonPath('plugins.0.checks', [
        ['id' => 'length', 'label' => 'Text length', 'state' => 'ok', 'message' => 'Fine.', 'field' => null, 'ownField' => null],
    ]);

    PluginFixtures::use();
    ($this->ask)($editor, $page->id, $page->working_revision_id)->assertOk()->assertExactJson(['plugins' => []]);
});
