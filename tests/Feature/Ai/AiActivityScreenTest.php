<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    aiFake();
    aiTask('tidy');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->screen = fn (array $query = []) => $this->actingAs($this->admin)->get(route('tools.ai-activity.index', $query));
});

it('shows an administrator the record, the filters\' options and the month\'s figures (I-1307)', function (): void {
    actvRow($this->editor);
    $newest = actvRow($this->editor);

    ($this->screen)()->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('rows', 2)
        ->where('rows.0.id', $newest)
        ->where('newer', null)
        ->where('older', null)
        ->where('filters', ['person' => null, 'task' => null, 'month' => null])
        ->where('people.0.label', 'Ada Admin')
        ->where('tasks', fn ($tasks): bool => $tasks->contains(['value' => 'tidy', 'label' => 'Tidy']))
        ->where('months.0.value', now()->utc()->format('Y-m'))
        ->where('summary.current', true)
        ->where('summary.requests', 2)
        ->where('summary.costMicrounits', 2_800)
        ->where('details', null)
        ->where('missing', false)
        ->where('aiOn', true)
        ->where('canChangeSettings', true)
        ->has('siteTimezone'));
});

it('refuses it to everyone without ai-activity.view, and sends a guest to sign in', function (string $who): void {
    if ($who === 'a guest') {
        $this->get(route('tools.ai-activity.index'))->assertRedirect();

        return;
    }

    $this->actingAs(User::factory()->create()->assignRole($who))->get(route('tools.ai-activity.index'))->assertForbidden();
})->with(['editor', 'author', 'contributor', 'a guest']);

it('reads the person, the task and the month from the address, and narrows the rows but not the month\'s figures', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    actvRow($this->editor);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    $mine = actvRow($this->editor);
    actvRow($this->admin);

    ($this->screen)(['person' => $this->editor->id, 'task' => 'tidy', 'month' => '2026-11'])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['person' => $this->editor->id, 'task' => 'tidy', 'month' => '2026-11'])
        ->has('rows', 1)
        ->where('rows.0.id', $mine)
        ->where('summary.label', 'November 2026')
        ->where('summary.requests', 2));
});

it('ignores a filter it can\'t read, and lists everything', function (string $case): void {
    actvRow($this->editor);

    $query = match ($case) {
        'a person that isn\'t a number' => ['person' => 'abc'],
        'a task that isn\'t a key' => ['task' => 'Not A Key'],
        'a month that doesn\'t exist' => ['month' => '2026-13'],
        default => ['month' => 'last'],
    };

    ($this->screen)($query)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters', ['person' => null, 'task' => null, 'month' => null])
        ->has('rows', 1));
})->with(['a person that isn\'t a number', 'a task that isn\'t a key', 'a month that doesn\'t exist', 'a month in words']);

it('pages by the size the person chose, and the next page opens from the cursor (I-140)', function (): void {
    lscrPrefer($this->admin, ['lists.per_page' => 25]);
    actvFiller($this->editor, 30);

    $first = ($this->screen)()->assertOk();
    $older = $first->viewData('page')['props']['older'];

    $first->assertInertia(fn (Assert $page) => $page->has('rows', 25)->where('newer', null));

    ($this->screen)(['after' => $older])->assertInertia(fn (Assert $page) => $page
        ->has('rows', 5)
        ->where('older', null)
        ->whereNot('newer', null));
});

it('opens a request by its address, though the list is on another page', function (): void {
    lscrPrefer($this->admin, ['lists.per_page' => 25]);
    $this->travelTo(CarbonImmutable::parse('2026-01-02 09:00:00', 'UTC'));
    $old = actvRow($this->editor, ['variant' => 'more-formal']);
    actvFiller($this->editor, 30);

    ($this->screen)(['row' => $old])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('details.id', $old)
        ->where('details.variant.label', 'More formal')
        ->where('missing', false)
        ->has('rows', 25)
        ->where('rows', fn ($rows) => ! in_array($old, collect($rows)->pluck('id')->all(), true)));
});

it('says so when a request is no longer in the record, and answers 404 to an address that isn\'t an ID', function (): void {
    actvRow($this->editor);

    ($this->screen)(['row' => strtolower((string) Str::ulid())])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('details', null)
        ->where('missing', true)
        ->has('rows', 1));

    ($this->screen)(['row' => 'not-an-id'])->assertNotFound();
    ($this->screen)(['row' => strtoupper((string) Str::ulid())])->assertNotFound();
});

it('still opens while AI is switched off, and says so (I-315)', function (): void {
    actvRow($this->editor);
    config(['teme.ai.enabled' => false]);

    ($this->screen)()->assertOk()->assertInertia(fn (Assert $page) => $page->where('aiOn', false)->has('rows', 1));
});

it('has no route that changes anything', function (): void {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'tools.ai-activity.'))
        ->flatMap(fn ($route): array => $route->methods())
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($methods)->toBe(['GET', 'HEAD']);

    $this->actingAs($this->admin)->post(route('tools.ai-activity.index'))->assertStatus(405);
});
it('is drawn by the page the controller names', function (): void {
    ($this->screen)()->assertOk()->assertInertia(fn (Assert $page) => $page->component('tools/ai-activity'));
});

it('labels every select on the page', function (): void {
    // A source scan: a control is labelled when it carries aria-label or aria-labelledby, or an id that a <Label htmlFor> in the file names.
    $source = uiComponent('pages/tools/ai-activity.tsx');

    preg_match_all('/<(?:Input|SelectField|select|textarea)\b((?:=>|[^>])*)>/s', $source, $controls);

    expect($controls[1])->toHaveCount(3);

    foreach ($controls[1] as $attributes) {
        $named = str_contains($attributes, 'aria-label=') || str_contains($attributes, 'aria-labelledby=');
        $labelled = preg_match('/\bid="([^"]+)"/', $attributes, $id) === 1 && str_contains($source, 'htmlFor="'.$id[1].'"');

        expect($named || $labelled)->toBeTrue("An unlabelled control:\n{$attributes}");
    }
});

it('gives the hub\'s card an icon of its own', function (): void {
    expect(uiComponent('lib/nav-icons.tsx'))->toContain('sparkles: Sparkles');
});
