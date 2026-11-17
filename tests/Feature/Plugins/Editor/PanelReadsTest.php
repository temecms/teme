<?php

declare(strict_types=1);

use App\Domain\Plugins\Editor\PanelTooSlow;
use App\Domain\Plugins\Editor\SuggestionRefused;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\PanelView;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/Linkfinder.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    ['faults' => $this->faults, 'clock' => $this->clock] = linkfinderEnabled();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->post = lifecycleDraft($this->editor, 'Pruning roses');
    $this->target = addrPublished($this->editor, 'Feeding roses in spring');

    $this->read = fn (?User $person = null) => $this->actingAs($person ?? $this->editor)
        ->getJson(route('content.panels.show', ['content' => $this->post->id, 'code' => 'linkfinder', 'panel' => 'links']));
    $this->suggest = function (Suggestion ...$suggestions): void {
        Linkfinder::$view = fn (): PanelView => new PanelView(status: '2 links found', tone: 'ok', suggestions: $suggestions);
    };
});

afterEach(function (): void {
    Linkfinder::reset();
    PluginFixtures::forget();
});

it('hands the view’s status and checked suggestions to the editor, told about the item and the person', function (): void {
    ($this->suggest)(
        new Suggestion('link-1', 'Link “feeding”', 'To Feeding roses in spring', SuggestedChange::link('feeding', $this->target->id, 2)),
        new Suggestion('title', 'A clearer title', '', SuggestedChange::title('Pruning roses in winter')),
        new Suggestion('excerpt', 'An excerpt', '', SuggestedChange::field('excerpt', 'When and how to prune.')),
        new Suggestion('noindex', 'Keep it listed', '', SuggestedChange::field('noindex', false)),
        new Suggestion('tags', 'Tag it', '', SuggestedChange::terms('tag', ['Roses', 'winter care'])),
        new Suggestion('body', 'A tidier opening', '', SuggestedChange::body(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prune in late winter.']]]]])),
    );

    $json = ($this->read)()->assertOk()->json();

    expect($json['status'])->toBe('2 links found')
        ->and($json['tone'])->toBe('ok')
        ->and($json['failed'])->toBeNull()
        ->and(array_column($json['suggestions'], 'id'))->toBe(['link-1', 'title', 'excerpt', 'noindex', 'tags', 'body'])
        ->and($json['suggestions'][0])->toBe(['id' => 'link-1', 'label' => 'Link “feeding”', 'detail' => 'To Feeding roses in spring', 'change' => [
            'kind' => 'link', 'phrase' => 'feeding', 'itemId' => $this->target->id, 'occurrence' => 2, 'target' => 'Feeding roses in spring',
        ]])
        ->and($json['suggestions'][1]['change'])->toBe(['kind' => 'title', 'title' => 'Pruning roses in winter'])
        ->and($json['suggestions'][2]['change'])->toMatchArray(['kind' => 'field', 'handle' => 'excerpt', 'label' => 'Excerpt', 'value' => 'When and how to prune.'])
        ->and($json['suggestions'][3]['change']['value'])->toBeFalse()
        ->and($json['suggestions'][4]['change'])->toMatchArray(['kind' => 'terms', 'taxonomy' => 'tag', 'terms' => [], 'create' => ['Roses', 'winter care']])
        ->and($json['suggestions'][5]['change']['kind'])->toBe('body')
        ->and($this->faults->seen)->toBe([]);

    $context = Linkfinder::$asked[0];

    expect($context)->toBeInstanceOf(EditorPanelContext::class)
        ->and([$context->itemId, $context->revisionId, $context->type, $context->personId, $context->permissions])
        ->toBe([$this->post->id, $this->post->working_revision_id, 'post', (string) $this->editor->id, ['suggest']]);
});

it('matches a suggested term that exists by its slug or its name', function (): void {
    DB::table('terms')->insert(['id' => (string) Str::ulid(), 'taxonomy' => 'tag', 'name' => 'Roses', 'slug' => 'roses', 'created_at' => now(), 'updated_at' => now()]);
    ($this->suggest)(new Suggestion('tags', 'Tag it', '', SuggestedChange::terms('tag', ['roses', 'Pests'])));

    $change = ($this->read)()->json('suggestions.0.change');

    expect(array_column($change['terms'], 'name'))->toBe(['Roses'])
        ->and($change['create'])->toBe(['Pests']);
});

it('says the panel couldn’t be shown, and records why, when the view throws or runs over its 250 ms', function (Closure $arrange, string $failure): void {
    $arrange($this);

    $json = ($this->read)()->assertOk()->json();

    expect($json['failed'])->toBe('This panel couldn’t be shown this time.')
        ->and($json['suggestions'])->toBe([])
        ->and($this->faults->seen)->toHaveCount(1)
        ->and($this->faults->seen[0][0])->toBe('tests/linkfinder')
        ->and($this->faults->seen[0][1])->toBe('tests/linkfinder#editor-panel')
        ->and($this->faults->seen[0][2])->toBe(EditorPanel::class)
        ->and($this->faults->seen[0][3])->toBeInstanceOf($failure);
})->with([
    'a throw' => [fn ($test) => Linkfinder::$view = fn () => throw new RuntimeException('The index is gone.'), RuntimeException::class],
    'over its budget' => [fn ($test) => $test->clock->steps = [0, 251_000_000], PanelTooSlow::class],
    'a wrong answer' => [fn ($test) => Linkfinder::$view = fn (): string => 'links', TypeError::class],
]);

it('drops a suggestion that fails its check against the item, and records it once', function (Closure $change): void {
    ($this->suggest)(
        new Suggestion('kept', 'A clearer title', '', SuggestedChange::title('Pruning roses in winter')),
        new Suggestion('dropped', 'Dropped', '', $change($this)),
    );

    $json = ($this->read)()->assertOk()->json();

    expect(array_column($json['suggestions'], 'id'))->toBe(['kept'])
        ->and($this->faults->seen)->toHaveCount(1)
        ->and($this->faults->seen[0][3])->toBeInstanceOf(SuggestionRefused::class);
})->with([
    'an unknown field' => [fn ($test) => SuggestedChange::field('mood', 'calm')],
    'a field of another kind' => [fn ($test) => SuggestedChange::field('noindex', 'yes')],
    'text over the field’s length' => [fn ($test) => SuggestedChange::field('seo_title', str_repeat('a', 201))],
    'a field a suggestion can’t set' => [fn ($test) => SuggestedChange::field('featured_image', 'image')],
    'a taxonomy the type doesn’t use' => [fn ($test) => SuggestedChange::terms('genre', ['Roses'])],
    'a link to a draft' => [fn ($test) => SuggestedChange::link('feeding', lifecycleDraft($test->editor, 'Unfinished')->id)],
    'a link to a private item' => [function ($test): SuggestedChange {
        DB::table('contents')->where('id', $test->target->id)->update(['visibility' => 'private']);

        return SuggestedChange::link('feeding', $test->target->id);
    }],
    'a link to the item itself' => [fn ($test) => SuggestedChange::link('roses', $test->post->id)],
    'a body the editor refuses' => [fn ($test) => SuggestedChange::body(['type' => 'doc', 'content' => [['type' => 'marquee']]])],
]);

it('hands markup over as text', function (): void {
    Linkfinder::$view = fn (): PanelView => new PanelView(status: "<b>Found</b>\u{202E} it", suggestions: [
        new Suggestion('title', '<img src=x onerror=alert(1)>', '<script>x</script>', SuggestedChange::title('<em>Roses</em>')),
    ]);

    $json = ($this->read)()->json();

    expect($json['status'])->toBe('<b>Found</b> it')
        ->and($json['suggestions'][0]['label'])->toBe('<img src=x onerror=alert(1)>')
        ->and($json['suggestions'][0]['detail'])->toBe('<script>x</script>')
        ->and($json['suggestions'][0]['change']['title'])->toBe('<em>Roses</em>');
});

it('lists the panel’s jobs on this item, with their progress, and asks again while one waits or runs', function (): void {
    $job = fn (string $state, string $item, string $panel = 'links', ?string $ended = null): string => tap((string) Str::ulid(), fn (string $id) => DB::table('tracked_jobs')->insert([
        'id' => $id, 'kind' => 'plugin-job', 'owner' => 'linkfinder', 'label' => "Find links: {$state}", 'starter' => 'person', 'started_by' => $this->editor->id, 'run_type' => 'plugin-job', 'run_id' => 'find-links',
        'state' => $state, 'total' => 10, 'done' => 4, 'message' => 'Reading articles', 'payload' => json_encode(['panel' => $panel, 'itemId' => $item]),
        'created_at' => now(), 'updated_at' => now(), 'ended_at' => $ended,
    ]));

    $running = $job('running', $this->post->id);
    $job('running', $this->target->id);
    $job('running', $this->post->id, 'other');
    $job('finished', $this->post->id, ended: now()->subMinutes(20)->toDateTimeString());

    $json = ($this->read)()->json();

    expect($json['jobs'])->toBe([['id' => $running, 'label' => 'Find links: running', 'state' => 'running', 'done' => 4, 'total' => 10, 'message' => 'Reading articles']])
        ->and($json['busy'])->toBeTrue();
});

it('is forbidden to a person who doesn’t see the panel, and never asks the plugin', function (): void {
    $author = User::factory()->create()->assignRole('author');

    ($this->read)($author)->assertForbidden();

    expect(Linkfinder::$asked)->toBe([]);
});
