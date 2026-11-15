<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Contents\Models\ContentAutosave;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
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
    $this->item = docSave(
        lifecycleDraft($this->author, 'Ferry fares rise'),
        $this->author,
        docWith(docText('Fares rise by 15% from 1 October.')),
        [BuiltInFields::SEO_TITLE => 'Fares', BuiltInFields::EXCERPT => 'Dearer crossings.'],
    )->refresh();
    $this->fake = mdReady();
    $this->edit = '/admin/content/'.$this->item->id.'/edit';

    // Warm the editor before the browser's first visit (I-638).
    $this->actingAs($this->author)->get($this->edit)->assertOk();
});

it('asks from Search and social and puts each proposal under its field, the excerpt\'s under the excerpt, overwriting nothing, and passes axe in the light and dark schemes (I-1313)', function (string $scheme): void {
    lscrPrefer($this->author, ['appearance.mode' => $scheme]);
    $this->fake->answer(mdAnswer());

    cmOpenSearch(cmEditorSettled(cmSignIn($this->author)->navigate($this->edit)))
        ->assertSee('AI proposes an SEO title')
        ->click('[data-test="metadata-suggest"]')
        ->waitForText(mdValues()['seo_title'])
        ->assertSeeIn('[data-ai-proposal="seo_title"]', mdValues()['seo_title'])
        ->assertSeeIn('[data-ai-proposal="meta_description"]', mdValues()['meta_description'])
        ->assertSeeIn('[data-ai-proposal="excerpt"]', mdValues()['excerpt'])
        // The social proposals wait under their fields on the Social tab.
        ->click('[data-test="search-section"] [role="tab"]:text-is("Social")')
        ->assertSeeIn('[data-ai-proposal="social_title"]', mdValues()['social_title'])
        ->assertSeeIn('[data-ai-proposal="social_description"]', mdValues()['social_description'])
        ->assertScript('(() => { const field = document.querySelector("#editor-excerpt"); const proposal = document.querySelector("[data-ai-proposal=excerpt]"); const body = document.querySelector("[data-slot=editor-body]"); return Boolean(field.compareDocumentPosition(proposal) & Node.DOCUMENT_POSITION_FOLLOWING) && Boolean(proposal.compareDocumentPosition(body) & Node.DOCUMENT_POSITION_FOLLOWING); })()')
        ->assertValue('#editor-seo-title', 'Fares')
        ->assertValue('#editor-excerpt', 'Dearer crossings.')
        ->assertSee('The proposals are under their fields.')
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);

it('uses one proposal as the assistant\'s revision, which Undo puts back as an ordinary edit, then all the rest as one change (I-1313, I-317)', function (): void {
    $this->fake->answer(mdAnswer());

    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->author)->navigate($this->edit)))
        ->click('[data-test="metadata-suggest"]')
        ->waitForText(mdValues()['seo_title'])
        ->click('[data-ai-proposal="seo_title"] [data-test="metadata-use"]')
        ->assertSee('Suggestion used as one change.')
        ->assertValue('#editor-seo-title', mdValues()['seo_title'])
        ->assertNotPresent('[data-ai-proposal="seo_title"]');

    $first = $this->item->refresh()->working_revision_id;

    expect(ContentRevision::query()->findOrFail($first)->only(['kind', 'origin']))->toBe(['kind' => 'ai', 'origin' => 'ai']);

    // Undo is an ordinary edit: the field goes back, the proposal stays used, and autosave builds on the assistant's revision.
    $page->click('Undo')
        ->assertValue('#editor-seo-title', 'Fares')
        ->assertNotPresent('[data-ai-proposal="seo_title"]')
        ->wait(3)
        ->assertDontSee('Changed by someone else');

    expect(ContentAutosave::query()->where('content_id', $this->item->id)->value('based_on_revision_id'))->toBe($first);

    $page->click('[data-test="metadata-use-all"]')
        ->assertSee('Suggestions used as one change.')
        ->assertValue('#editor-excerpt', mdValues()['excerpt'])
        ->assertValue('#editor-social-title', mdValues()['social_title'])
        ->assertValue('#editor-seo-title', 'Fares')
        ->assertNotPresent('[data-ai-proposal]')
        ->assertSee('Ask for a suggestion and it appears here.');

    $all = $this->item->refresh()->working_revision_id;

    expect(ContentRevision::query()->findOrFail($all)->only(['kind', 'origin']))->toBe(['kind' => 'ai', 'origin' => 'ai'])
        ->and(AiActivity::query()->sole()->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Applied, 'revision_id' => $first]);
});

it('asks from the assistant pane, whose card says where the proposals are, opens Search and social to show them, and Discard puts them all aside (I-1313, I-321, I-3124)', function (): void {
    $this->fake->answer(mdAnswer());

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))
        ->click('[aria-label="Assistant"]')
        ->assertSeeIn('[aria-labelledby="assistant-metadata"]', 'Search and social')
        ->click('[data-test="metadata-suggest-pane"]')
        ->waitForText('The proposals are under their fields.');

    // The section was closed; the proposals opened it, so they are in sight under their fields.
    cmWaitUntil($page, "document.getElementById('editor-search-fields')?.hidden === false && document.querySelector('[data-ai-proposal=\"seo_title\"]')?.offsetParent !== null");

    $page->assertSee('Metadata: search and social text, and the excerpt')
        ->click('[data-test="ai-discard"]')
        ->assertNotPresent('[data-ai-proposal]')
        ->assertValue('#editor-seo-title', 'Fares');

    expect(AiActivity::query()->sole()->outcome)->toBe(AiOutcome::Discarded);
});
