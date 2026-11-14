<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\Visibility;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->harbour = cmItem($this->editor, 'Harbour news');
    $this->actingAs($this->editor)->get(route('content.edit', ['content' => $this->harbour->id]))->assertOk();
});

it('makes a post private from the Publish panel at once, says what that means, and keeps it after a reload (I-1700)', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    // In one attempt each: navigate()'s one-second attempts, on a slow page, leave a timed-out answer that a
    // later call reads as its own, "Timeout 1000ms exceeded" (issue 415).
    $page = cmNavigate(cmSignIn($this->editor), "/admin/content/{$this->harbour->id}/edit");

    cmEditorSettled($page)
        ->click('#editor-visibility')
        ->click('[role="option"] >> text="Private"');
    // The options close before axe or the next look: assertNotPresent never waits. The field is
    // enabled again once the save has answered, so the reload reads what was saved.
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null && document.querySelector('#editor-visibility-meaning')?.textContent.includes('here in the admin') && document.querySelector('#editor-visibility')?.disabled === false");
    cmNavigate($page, "/admin/content/{$this->harbour->id}/edit");
    cmEditorSettled($page);
    cmWaitUntil($page, "document.querySelector('#editor-visibility-meaning')?.textContent.includes('On the site its address answers')");
    expect(cmAxeViolations($page))->toBe([]);

    expect(Content::query()->findOrFail($this->harbour->id)->visibility)->toBe(Visibility::Private)
        ->and(addrGet('/harbour-news/')->getStatusCode())->toBe(404);
})->with(['light', 'dark']);

it('protects a post only when Protect is pressed, shows a refusal under the field, and reads the password back (I-1700)', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $page = cmNavigate(cmSignIn($this->editor), "/admin/content/{$this->harbour->id}/edit");

    cmEditorSettled($page)
        ->click('#editor-visibility')
        ->click('[role="option"] >> text="Password protected"');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null && document.querySelector('#editor-password') !== null");

    expect(Content::query()->findOrFail($this->harbour->id)->visibility)->toBe(Visibility::Public);

    $page->click('[data-test="visibility-protect"]');
    cmWaitUntil($page, "document.querySelector('#editor-password')?.getAttribute('aria-invalid') === 'true'");
    $page->assertSee('Enter a password of up to 255 characters.')
        ->type('#editor-password', 'Harbour 2024')
        ->click('[data-test="visibility-protect"]');
    cmWaitUntil($page, "document.querySelector('#editor-password')?.getAttribute('aria-invalid') !== 'true' && document.querySelector('[data-test=\"visibility-protect\"]') === null");
    // In one attempt: a timed-out attempt's answer, read by the calls after it, shifts each script()'s
    // answer one call back, and axe's read the wait's true, "axe did not answer".
    cmNavigate($page, "/admin/content/{$this->harbour->id}/edit");
    cmEditorSettled($page);
    cmWaitUntil($page, "document.querySelector('#editor-password')?.value === 'Harbour 2024'");
    expect(cmAxeViolations($page))->toBe([]);

    expect(ReaderPasswords::open(Content::query()->findOrFail($this->harbour->id)))->toBe('Harbour 2024');
})->with(['light', 'dark']);
