<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Fields\BuiltInFields;
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

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->draft = lifecycleDraft($this->editor, 'Harbour news');
    $this->edit = fn (Content $item): string => "/admin/content/{$item->id}/edit";

    // The fields the editor last saved: this person's autosave of the article.
    $this->saved = fn (Content $item): array => json_decode((string) DB::table('content_autosaves')->where('content_id', $item->id)->value('fields'), true) ?? [];

    // Waits until what was typed last is saved: first until the save state's hook has noticed the change, then until it says saved, with a time (issue #83).
    $this->settled = function (mixed $page): mixed {
        $state = "document.querySelector('[data-test=\"save-state\"]')";

        cmWaitUntil($page, "{$state}?.dataset.state === 'saving'");

        return cmWaitUntil($page, "{$state}?.dataset.state === 'saved' && {$state}?.dataset.savedAt !== undefined");
    };

    // Waits for words on the page, or within one element.
    $this->textShown = fn (mixed $page, string $text, string $within = 'body'): mixed => cmWaitUntil($page, '(document.querySelector('.json_encode($within, JSON_THROW_ON_ERROR).")?.innerText ?? '').includes(".json_encode($text, JSON_THROW_ON_ERROR).')');

    // Waits until a dialog is open and its animations are over, then until it is gone.
    $this->dialogOpen = fn (mixed $page): mixed => cmWaitUntil($page, "{$this->arrived} === 0");
    $this->dialogGone = fn (mixed $page): mixed => cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') === null");

    // Waits until an open dialog has finished arriving: its content's and overlay's own animations (issue #60).
    $this->arrived = "(() => { const parts = [...document.querySelectorAll('[data-slot=\"dialog-content\"], [data-slot=\"dialog-overlay\"]')]; return parts.length === 0 ? -1 : parts.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()";

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(($this->edit)($this->draft))->assertOk();
});

it('confirms a draft\'s first publication with core\'s checks, and publishes the revision it checked (I-880)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)))
        ->click('[aria-label="Post settings"] button:text-is("Publish")');

    ($this->textShown)($page, 'Publish this post?');
    ($this->dialogOpen)($page);

    $page->assertSeeIn('[data-test="confirmation-checks"]', 'None, and no excerpt. Search engines will choose text from the page.')
        ->assertSeeIn('[data-test="confirmation-checks"]', 'Nothing left out.')
        ->assertSee('Warnings don’t stop you.')
        ->click('[role="dialog"] button:text-is("Publish")');

    ($this->dialogGone)($page);
    ($this->textShown)($page, 'Published.');

    expect(Content::query()->findOrFail($this->draft->id)->status)->toBe('published');
});

it('updates a published item at once, as before, with no confirmation (I-880)', function (): void {
    $live = addrPublished($this->editor, 'Ferry fares');

    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($live))))
        ->type('#editor-seo-title', 'Ferry fares rise in the spring');

    ($this->settled)($page);

    $page->click('[aria-label="Post settings"] button:text-is("Update")');

    ($this->textShown)($page, 'Published.');
    ($this->dialogGone)($page);

    $content = Content::query()->findOrFail($live->id);

    expect((array) $content->fields)->toMatchArray([BuiltInFields::SEO_TITLE => 'Ferry fares rise in the spring']);
});

it('schedules a draft only from the confirmation, and a cancel schedules nothing', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)))
        ->click('#editor-when')
        ->click('[role="option"] >> text="At a set time…"');

    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null");

    $page->click('button:text-is("Tomorrow morning")')
        ->click('[aria-label="Post settings"] button:text-is("Schedule")');

    ($this->textShown)($page, 'Schedule this post?');
    ($this->dialogOpen)($page);

    $page->assertSee('It publishes')
        ->click('[role="dialog"] button:text-is("Cancel")');

    ($this->dialogGone)($page);
    // The choice stays, so the writer can try again.
    ($this->textShown)($page, 'At a set time…', '[data-test="publish-when"]');

    expect(DB::table('scheduled_publications')->where('content_id', $this->draft->id)->count())->toBe(0)
        ->and(Content::query()->findOrFail($this->draft->id)->status)->toBe('draft');
});

it('sets the article type and the snippet controls in the closed Advanced fold, its trigger naming them (I-709, I-714, I-879)', function (): void {
    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft))));
    cmWaitUntil($page, "document.querySelector('#editor-article-type') !== null && document.querySelector('#editor-article-type').offsetParent === null");
    $page->click('#editor-advanced')
        ->click('#editor-article-type')
        ->click('[role="option"] >> text="News article"');

    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null");

    $page->click('#editor-nosnippet');
    cmWaitUntil($page, "document.querySelector('#editor-max-snippet')?.disabled === true");

    $page->assertSee('Not used while “Don’t show a snippet” is ticked.')
        ->click('#editor-noarchive');

    ($this->textShown)($page, 'News article · No snippet · No cached copy', '[data-test="advanced-summary"]');
    ($this->settled)($page);

    expect(($this->saved)($this->draft))->toMatchArray([
        BuiltInFields::ARTICLE_TYPE => 'NewsArticle',
        BuiltInFields::NOSNIPPET => true,
        BuiltInFields::NOARCHIVE => true,
    ]);
});

it('offers the primary category once two are ticked, says it moves a published post, and moves it when the post is updated (I-869, I-881)', function (): void {
    addrChange(User::factory()->create()->assignRole('administrator'), ['patterns' => ['post' => '/{category}/{slug}/']]);
    $news = sdCategory('News');
    $local = sdCategory('Local', $news);
    $sport = sdCategory('Sport');
    $post = headPost($this->editor, 'Harbour news', [], [$sport]);

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($post)))
        ->assertNotPresent('#editor-primary-category')
        ->click('label:text-is("Local")');

    ($this->textShown)($page, 'First ticked: Sport', '#editor-primary-category');

    $page->click('#editor-primary-category')
        ->click('[role="option"] >> text="Local"');

    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null");
    ($this->textShown)($page, 'Names this post’s section above its title and in lists. It also sets the category in its address.', '[data-test="primary-category-hint"]');

    $page->assertSeeIn('[data-test="primary-category-hint"]', 'Changing it moves this post’s address when you update it. The old address leads to the new one.')
        ->assertDontSeeIn('[data-test="primary-category-hint"]', 'Its trail:');

    ($this->settled)($page);

    $page->click('[aria-label="Post settings"] button:text-is("Update")');

    ($this->textShown)($page, 'Published.');

    // The draft beforeEach makes holds "harbour-news", so this post's slug is its own.
    expect(addrCurrent('content', $post->id))->toBe("news/local/{$post->slug}")
        ->and(addrLocation(addrGet("/sport/{$post->slug}/")->assertStatus(301)))->toBe("/news/local/{$post->slug}/")
        // The update gathered the autosave into the published revision, so its fields are read there.
        ->and((array) Content::query()->findOrFail($post->id)->fields)->toMatchArray([BuiltInFields::PRIMARY_CATEGORY => $local->id]);
});

it('passes axe with the Advanced fold open and with the confirmation open, in the light and dark schemes, every target 24 pixels or more', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmOpenSearch(cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft))))
        ->click('#editor-advanced')
        ->hover('#editor-page-type');

    expect(cmAxeViolations($page))->toBe([]);

    $fold = cmSmallTargets($page);

    $page->click('[aria-label="Post settings"] button:text-is("Publish")');

    ($this->textShown)($page, 'Publish this post?');
    ($this->dialogOpen)($page);

    $page->assertNoAccessibilityIssues(3);

    $confirmation = cmSmallTargets($page);

    expect($fold['small'])->toBe([])
        ->and($fold['checked'])->toBeGreaterThan(0)
        ->and($confirmation['small'])->toBe([])
        ->and($confirmation['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
