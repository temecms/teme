<?php

declare(strict_types=1);

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Operations\Input\SettleAiActivityInput;
use App\Domain\Ai\Operations\SettleAiActivity;
use App\Domain\Ai\UsageLimits;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    aiFake();
    aiTask('tidy');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->item = lifecycleDraft($this->editor, 'Harbour news');

    // Three requests: one used, one that failed before the model was asked, one at a model with no price.
    $this->used = actvRow($this->editor, ['subjectId' => $this->item->id]);
    app(SettleAiActivity::class)->execute(new SettleAiActivityInput($this->used, AiOutcome::Applied, $this->item->working_revision_id, (string) $this->editor->id));
    $this->failed = actvRow($this->editor, ['subjectId' => $this->item->id, 'outcome' => AiOutcome::Failed, 'failure' => AiFailureType::Timeout, 'usage' => new AiUsage, 'costMicrounits' => null, 'variant' => null]);
    $this->unpriced = actvRow($this->admin, ['subjectId' => $this->item->id, 'model' => 'fake-unpriced', 'costMicrounits' => null, 'variant' => 'more-formal']);
    cmSettings([UsageLimits::BUDGET => 50], AiSettings::KEY);

    // True when the sheet's own animations have finished; never its subtree's, where a skeleton animates for ever.
    $this->sheetSettled = "(() => { const sheet = [...document.querySelectorAll('[data-slot=\"sheet-content\"], [data-slot=\"sheet-overlay\"]')]; return sheet.length === 0 ? -1 : sheet.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()";

    // Waits for words on the page, then (for a drawer) until its sheet has stopped moving; the plugin's assertSee() looks once (issue #83).
    $this->textShown = fn (mixed $page, string $text, bool $shown = true): mixed => cmWaitUntil($page, ($shown ? '' : '!').'document.body.innerText.includes('.json_encode($text, JSON_THROW_ON_ERROR).')');
    $this->drawerShows = fn (mixed $page, string $text): mixed => cmWaitUntil($page, "[...document.querySelectorAll('[data-slot=\"sheet-content\"]')].some((sheet) => sheet.innerText.includes(".json_encode($text, JSON_THROW_ON_ERROR).")) && {$this->sheetSettled} === 0");
    $this->queryHas = fn (mixed $page, string $name, bool $has = true): mixed => cmWaitUntil($page, ($has ? '' : '!').'new URL(location.href).searchParams.has('.json_encode($name, JSON_THROW_ON_ERROR).')');

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->admin)->get(route('tools.ai-activity.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('content.edit', $this->item->id))->assertOk();
});

it('passes axe on Tools › AI activity and on a request\'s drawer, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/ai-activity');
    ($this->textShown)($page, '1 request used a model with no price, so its cost is left out.');

    $page->assertSee('Every AI request, newest first.')
        ->assertSee('$0.0014')
        ->assertSee('Monthly budget')
        ->assertSee('$50.00')
        ->assertSee('The AI service took too long to answer.')
        ->assertSee('1 request used a model with no price, so its cost is left out.')
        ->assertNoAccessibilityIssues(3);

    $list = cmSmallTargets($page);

    $page->click("[data-test=\"ai-row-details-{$this->used}\"]");
    ($this->drawerShows)($page, 'The suggestion was used.');

    $page->assertSee('Tidy request')
        ->assertNoAccessibilityIssues(3);

    $drawer = cmSmallTargets($page);

    expect($list['small'])->toBe([])
        ->and($list['checked'])->toBeGreaterThan(0)
        ->and($drawer['small'])->toBe([])
        ->and($drawer['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('narrows the list to one person through the real select', function (): void {
    $page = cmSignIn($this->admin)
        ->navigate('/admin/tools/ai-activity')
        ->click('#ai-person')
        ->click('[role="option"] >> text="Ada Admin"');

    // The list changes as the choice lands, with no Filter button.
    ($this->textShown)($page, 'Clear filters');

    $page->assertQueryStringHas('person', (string) $this->admin->id)
        ->assertSee('More formal')
        ->assertDontSee('The AI service took too long to answer.')
        ->assertDontSee('Shorter')
        ->assertSee('Clear filters');
});

it('pages through the record with Older and Newer', function (): void {
    lscrPrefer($this->admin, ['lists.per_page' => 25]);
    actvFiller($this->editor, 30);

    $page = cmSignIn($this->admin)->navigate('/admin/tools/ai-activity');
    ($this->textShown)($page, 'More formal');

    $page->click('Older');
    ($this->textShown)($page, 'A deleted item');

    $page->assertQueryStringHas('after')
        ->assertDontSee('More formal')
        ->click('Newer');
    ($this->textShown)($page, 'More formal');
});

it('opens a request\'s drawer from its address, and closes it back to the list', function (): void {
    $page = cmSignIn($this->admin)->navigate("/admin/tools/ai-activity?row={$this->failed}");
    ($this->drawerShows)($page, 'The AI service took too long to answer. Nothing was changed.');

    $page->assertSee('Tidy request')
        ->assertSee('2.3 seconds')
        ->press('Close');

    cmWaitUntil($page, "{$this->sheetSettled} === -1");
    ($this->queryHas)($page, 'row', false);
});

it('follows a revision in the History pane to its request', function (): void {
    DB::table('content_revisions')->where('id', $this->item->working_revision_id)->update(['origin' => 'ai']);

    $page = cmEditorSettled(cmSignIn($this->admin)->navigate("/admin/content/{$this->item->id}/edit"))
        ->click('[aria-label="History"]')
        // The pane's list is fetched; hover() waits for the link to the request.
        ->hover('[aria-label="Assistant · Shorter, in AI activity"]')
        ->assertSee('Assistant · Shorter')
        ->click('[aria-label="Assistant · Shorter, in AI activity"]');

    ($this->drawerShows)($page, 'Tidy request');

    $page->assertPathIs('/admin/tools/ai-activity')
        ->assertQueryStringHas('row', $this->used);
});
