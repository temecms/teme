<?php

declare(strict_types=1);

use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // The warm-up below signs in through actingAs(), and the browser must not inherit it.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    $this->item = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday.');
    $this->fake = aiReady();
    $this->edit = '/admin/content/'.$this->item->id.'/edit';

    // Warm the editor before the browser's first visit (I-638, B-356).
    $this->actingAs($this->author)->get($this->edit)->assertOk();

    // Waits until an element's visible text has, or has lost, a sentence; the plugin's assertSee() looks once (issue #83).
    $this->textShown = fn (mixed $page, string $text, bool $shown = true, string $within = 'body'): mixed => cmWaitUntil($page, ($shown ? '' : '!').'(document.querySelector('.json_encode($within, JSON_THROW_ON_ERROR).')?.innerText ?? \'\').includes('.json_encode($text, JSON_THROW_ON_ERROR).')');

    // What a task's menu or the palette sends: the whole first paragraph, which starts at 0 and is 18 long.
    $this->ask = "window.dispatchEvent(new CustomEvent('teme:assistant', { detail: { task: 'fixture', scope: 'Fixture: the first paragraph', before: 'The ferry is late.', target: { path: '0', from: 0, to: 18, pos: 0, text: 'The ferry is late.' } } }))";
});

it('opens the assistant from its icon and with Ctrl+J, says where text goes, and passes axe in the light and dark schemes (I-1304, I-238)', function (string $scheme): void {
    lscrPrefer($this->author, ['appearance.mode' => $scheme]);

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))
        ->click('[aria-label="Assistant"]');

    ($this->textShown)($page, 'Ask for a suggestion and it appears here.');

    $page->assertSee('What you ask about is sent to Fake AI.')
        ->assertSee('Ctrl + J or ⌘ J opens and closes this pane.')
        ->assertDontSee('Change this in Settings › AI')
        ->assertNoAccessibilityIssues(3);

    $page->keys('.teme-prose', 'Control+j');
    ($this->textShown)($page, 'Ask for a suggestion and it appears here.', false);

    $page->keys('.teme-prose', 'Control+j');
    ($this->textShown)($page, 'Ask for a suggestion and it appears here.');
})->with(['light', 'dark']);

it('shows no assistant to someone who may not ask AI', function (): void {
    $contributor = User::factory()->create(['name' => 'Cy Contributor'])->assignRole('contributor');
    $own = aiDraft($contributor, 'A draft of my own.');
    $this->actingAs($contributor)->get('/admin/content/'.$own->id.'/edit')->assertOk();

    cmSignIn($contributor)
        ->navigate('/admin/content/'.$own->id.'/edit')
        ->assertPresent('[aria-label="History"]')
        ->assertNotPresent('[aria-label="Assistant"]');
});

it('asks, shows the proposal with its changes marked and where it went, and uses it as one change that Undo takes back (I-317, I-203)', function (): void {
    $this->fake->answer(aiText('The boat is late.'));

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('The ferry is late.');
    $page->script($this->ask);

    // hover() waits for the proposal's Use: the answer has come. waitForText() would match the pane's hint, "is sent to Fake AI".
    $page->hover('[data-test="ai-use"]')
        ->assertSee('Fixture: the first paragraph')
        ->assertSeeIn('[aria-labelledby="assistant-request"] del', 'ferry')
        ->assertSeeIn('[aria-labelledby="assistant-request"] ins', 'boat')
        ->assertSeeIn('[aria-labelledby="assistant-request"]', 'Sent to Fake AI')
        ->assertNoAccessibilityIssues(3)
        ->click('[data-test="ai-use"]');

    ($this->textShown)($page, 'The boat is late.', within: '.teme-prose');
    ($this->textShown)($page, 'Suggestion used as one change.');

    expect(ContentRevision::query()->findOrFail($this->item->refresh()->working_revision_id)->only(['kind', 'origin']))
        ->toBe(['kind' => 'ai', 'origin' => 'ai'])
        ->and(AiActivity::query()->sole()->outcome)->toBe(AiOutcome::Applied);

    $assistant = $this->item->refresh()->working_revision_id;

    // Undo is the editor's own; its autosave, two seconds later, builds on the assistant's revision, never stale.
    $page->click('Undo');
    ($this->textShown)($page, 'The ferry is late.', within: '.teme-prose');

    $page->wait(3)
        ->assertDontSee('Changed by someone else');

    expect(ContentAutosave::query()->where('content_id', $this->item->id)->value('based_on_revision_id'))->toBe($assistant);
});

it('says plainly when AI fails, and puts a proposal aside with Discard, in the dark scheme too', function (): void {
    lscrPrefer($this->author, ['appearance.mode' => 'dark']);
    $this->fake->fail(AiFailure::refused())->answer(aiText('The boat is late.'));

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('The ferry is late.');
    $page->script($this->ask);

    $page->hover('[data-test="ai-dismiss"]')
        ->assertSee('The AI service declined to answer.')
        ->click('[data-test="ai-dismiss"]');
    ($this->textShown)($page, 'Ask for a suggestion and it appears here.');

    $page->script($this->ask);

    $page->hover('[data-test="ai-use"]')
        ->assertSeeIn('[aria-labelledby="assistant-request"] ins', 'boat')
        ->assertNoAccessibilityIssues(3)
        ->click('[data-test="ai-discard"]');
    ($this->textShown)($page, 'Ask for a suggestion and it appears here.');

    $page->assertSeeIn('.teme-prose', 'The ferry is late.');

    expect(AiActivity::query()->pluck('outcome')->map(fn (AiOutcome $outcome): string => $outcome->value)->sort()->values()->all())
        ->toBe(['discarded', 'refused']);
});
