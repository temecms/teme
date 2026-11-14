<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\AdvanceBulkRun;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Jobs\BulkRuns;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->matching = fn (User $person, array $body, string $type = 'posts'): mixed => $this->actingAs($person)->from("/admin/{$type}")->post(route('content.bulk-matching', ['type' => $type]), $body);
});

it('starts a run from the list and says so', function (): void {
    Queue::fake();
    BulkRuns::drafts($this->editor, 2);

    ($this->matching)($this->editor, ['action' => 'trash', 'filters' => ['tab' => 'all']])
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Started: Move all posts to the trash. It runs in the background, and your inbox tells you when it’s done.']);

    Queue::assertPushed(AdvanceBulkRun::class, 1);
});

it('reads only the list’s own keys from the filters it’s sent', function (): void {
    Queue::fake();

    ($this->matching)($this->editor, ['action' => 'trash', 'filters' => ['tab' => 'drafts', 'search' => 'harbour', 'bogus' => 'x', 'ids' => ['a']]])
        ->assertRedirect('/admin/posts');

    expect(json_decode((string) DB::table('bulk_runs')->value('filters'), true))->toEqual(['tab' => 'drafts', 'search' => 'harbour']);
});

it('starts a run over the All tab when it’s sent no filters at all', function (): void {
    Queue::fake();

    ($this->matching)($this->editor, ['action' => 'trash'])->assertRedirect('/admin/posts');

    expect(DB::table('tracked_jobs')->value('label'))->toBe('Move all posts to the trash');
});

it('refuses an action it doesn’t know, starting nothing', function (): void {
    ($this->matching)($this->editor, ['action' => 'publish', 'filters' => ['tab' => 'all']])
        ->assertRedirect('/admin/posts')
        ->assertSessionHasErrors('action');

    expect(DB::table('bulk_runs')->count())->toBe(0);
});

it('refuses an action the tab can’t take, in words the bar shows', function (string $action, string $tab, string $words): void {
    ($this->matching)($this->editor, ['action' => $action, 'filters' => ['tab' => $tab]])
        ->assertRedirect('/admin/posts')
        ->assertSessionHasErrors(['action' => $words]);
})->with([
    'moving the trash to the trash' => ['trash', 'trash', 'These are in the trash already.'],
    'deleting outside the trash' => ['delete', 'all', 'Only items in the trash can be restored or deleted permanently.'],
]);

it('keeps out someone who may not create content', function (): void {
    ($this->matching)(User::factory()->create(), ['action' => 'trash', 'filters' => ['tab' => 'all']])->assertForbidden();
});

it('lets an author start a run over their own posts', function (): void {
    Queue::fake();

    ($this->matching)($this->author, ['action' => 'trash', 'filters' => ['tab' => 'mine']])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Started: Move all your posts to the trash. It runs in the background, and your inbox tells you when it’s done.']);
});

it('answers the list again without the items a run moved, once it has finished', function (): void {
    BulkRuns::drafts($this->editor, 3);

    ($this->matching)($this->editor, ['action' => 'trash', 'filters' => ['tab' => 'all']]);

    $this->actingAs($this->editor)->get('/admin/posts')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', [])
            ->where('tabs', fn ($tabs): bool => collect($tabs)->firstWhere('key', 'trash')['count'] === 3));
});

it('fills the bar’s signpost with Select all matching, after its buttons, and leaves the row’s suffix alone', function (): void {
    $list = uiComponent('pages/content/index.tsx');

    expect($list)->toContain('<SelectAllMatching')
        ->not->toContain('Later (I-143)')
        ->toContain('Later (I-1451)')
        ->and(strpos($list, 'Move to trash'))->toBeLessThan(strpos($list, '<SelectAllMatching'));
});

it('sends the list’s filters, never its rows, and goes back to the page’s selection when the rows change', function (): void {
    expect(uiComponent('pages/content/index.tsx'))
        ->toContain('BulkMatchingController')
        ->toContain('filters: page.filters')
        ->toContain('setAllMatching(false)')
        ->toContain("'teme:jobs-ended'");
});

it('words the confirmations as the design pass does', function (): void {
    expect(uiComponent('components/list/select-all-matching.tsx'))
        ->toContain('This runs in the background, one ')
        ->toContain('Any you may not change are skipped.')
        ->toContain('You can restore them from the trash.')
        ->toContain('All their revisions go with them. This can’t be undone.')
        ->toContain(' of your ')
        ->toContain(' in the trash')
        ->toContain(' tab');
});

it('lets the bar say that all matching items are selected', function (): void {
    expect(uiComponent('components/list/bulk-bar.tsx'))->toContain('selectedLabel');
});
