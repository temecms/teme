<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Jobs\BulkRuns;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (JobsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    lscrPrefer($this->editor, ['lists.per_page' => 25]);

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get('/admin/posts')->assertOk();
});

it('selects every post across the pages and moves them to the trash once confirmed, passing axe with the confirmation open', function (): void {
    $ids = BulkRuns::drafts($this->editor, 30);

    $page = cmSignIn($this->editor)->navigate('/admin/posts');
    cmWaitUntil($page, <<<'JS'
        document.querySelectorAll('tbody tr').length === 25
        JS);
    $page->click('[aria-label="Select all on this page"]');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="select-all-matching"]')?.textContent === 'Select all 30 posts'
        JS);
    $page->click('[data-test="select-all-matching"]');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[aria-label="Bulk actions"]')?.textContent.includes('30 posts selected')
        JS);
    $page->click('[aria-label="Bulk actions"] button >> text="Move to trash"');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="confirm-matching"][role="dialog"]')?.textContent.includes('Move all 30 posts to the trash?')
            && document.querySelector('[data-test="confirm-matching"]').getAnimations().every((animation) => animation.playState !== 'running')
        JS);
    $page->assertNoAccessibilityIssues(3)
        ->click('[data-test="confirm-matching-go"]');
    cmWaitUntil($page, <<<'JS'
        document.body.textContent.includes('Started: Move all posts to the trash.')
        JS);

    expect(DB::table('contents')->whereIn('id', $ids)->whereNotNull('deleted_at')->count())->toBe(30);
});

it('offers all matching posts under a search, with no count, and moves only those', function (): void {
    $ids = BulkRuns::drafts($this->editor, 30);
    $other = lifecycleDraft($this->editor, 'Market day');

    $page = cmSignIn($this->editor)->navigate('/admin/posts?search=Draft');
    cmWaitUntil($page, <<<'JS'
        document.querySelectorAll('tbody tr').length === 25
        JS);
    $page->click('[aria-label="Select all on this page"]');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="select-all-matching"]')?.textContent === 'Select all matching posts'
        JS);
    $page->click('[data-test="select-all-matching"]');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[aria-label="Bulk actions"]')?.textContent.includes('All matching posts selected')
        JS);
    $page->click('[aria-label="Bulk actions"] button >> text="Move to trash"');
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="confirm-matching"][role="dialog"]')?.textContent.includes('Move all matching posts to the trash?')
            && document.querySelector('[data-test="confirm-matching"]').getAnimations().every((animation) => animation.playState !== 'running')
        JS);
    $page->click('[data-test="confirm-matching-go"]');
    cmWaitUntil($page, <<<'JS'
        document.body.textContent.includes('Started: Move matching posts to the trash.')
        JS);

    expect(DB::table('contents')->whereIn('id', $ids)->whereNotNull('deleted_at')->count())->toBe(30)
        ->and(DB::table('contents')->where('id', $other->id)->value('deleted_at'))->toBeNull();
});
