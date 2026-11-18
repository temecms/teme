<?php

declare(strict_types=1);

use App\Domain\Ai\AiSettings;
use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Jobs\JobState;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Editor\PanelView;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/../../Feature/Plugins/Editor/Linkfinder.php';

uses(RefreshDatabase::class);

// A plugin's panel in the editor:
// Link Finder, which works on the article being edited, pinned to the bar,
// asked for links, and its suggestions used or discarded by the person.

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    // The panel's job waits, so its progress shows.
    Queue::fake();
    linkfinderEnabled();

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->target = addrPublished($this->editor, 'Feeding roses in spring');
    $this->post = docSave(lifecycleDraft($this->editor, 'Pruning roses'), $this->editor, docWith(docText('Prune in winter, then think about feeding.')));
    $this->edit = "/admin/content/{$this->post->id}/edit";

    $suggestions = [
        new Suggestion('link-1', 'Link “feeding”', 'A published article on the same subject.', SuggestedChange::link('feeding', $this->target->id)),
        new Suggestion('title', 'A clearer title', '', SuggestedChange::title('Pruning roses in winter')),
        new Suggestion('excerpt', 'An excerpt', '', SuggestedChange::field('excerpt', 'When and how to prune.')),
        new Suggestion('body', 'A tidier opening', '', SuggestedChange::body(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prune roses in late winter.']]]]])),
        new Suggestion('extra', 'Link “winter”', '', SuggestedChange::title('Winter pruning')),
    ];
    Linkfinder::$view = fn (): PanelView => new PanelView(status: '2 links found', tone: 'ok', suggestions: array_values(array_filter(
        $suggestions,
        fn (Suggestion $suggestion): bool => ! in_array($suggestion->id, array_column(Linkfinder::$settles, 0), true),
    )));

    // How far the page scrolls sideways: none. The kept scrollbar gutter makes the raw difference negative.
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');

    // Waits until an element's visible text has, or has lost, a sentence; assertSee() looks once.
    $this->shown = fn (mixed $page, string $text, bool $shown = true, string $within = 'body'): mixed => cmWaitUntil($page, ($shown ? '' : '!').'(document.querySelector('.json_encode($within, JSON_THROW_ON_ERROR).')?.innerText ?? \'\').includes('.json_encode($text, JSON_THROW_ON_ERROR).')');

    $this->panel = '[data-test="plugin-panel"]';

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

afterEach(function (): void {
    Linkfinder::reset();
    PluginFixtures::forget();
});

it('lists the panel in Tools, pins it to a wide bar, and draws it, the plugin’s name once in its header, with no accessibility issue', function (string $scheme, int $width): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    // A narrow screen has no sidebar to settle, so it waits for the writing area alone.
    $page = cmNavigate(cmSignIn($this->editor)->resize($width, 900), $this->edit);
    $width > 640 ? cmEditorSettled($page) : cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");

    // Tools lists the panel under Plugins, with the plugin's name beside it.
    $page->click('header [data-test="editor-tools"]');
    cmWaitUntil($page, "document.activeElement?.getAttribute('type') === 'search' && document.activeElement.closest('[role=\"dialog\"]') !== null");
    $page->assertSeeIn('[role="dialog"]', 'Plugins')
        ->assertSeeIn('[role="dialog"] [data-slot="tool-row"]:has-text("Link suggestions")', 'Link Finder');

    if ($width > 640) {
        // Pinned, it joins the bar, and opens from there.
        $page->click('button[aria-label="Pin Link suggestions to the bar"]');
        cmWaitUntil($page, "document.querySelector('button[aria-label=\"Unpin Link suggestions\"]')?.getAttribute('aria-pressed') === 'true' && document.querySelector('[data-slot=\"tool-row\"]')?.closest('[aria-busy]')?.getAttribute('aria-busy') === 'false'");
        $page->keys('[role="dialog"] input[type="search"]', 'Escape');
        cmWaitUntil($page, "document.querySelector('[role=\"dialog\"]') === null");

        expect($this->editor->refresh()->preferences['editor.pinned_tools'] ?? [])->toContain('plugin.linkfinder.links');

        $page->click('header button[aria-label="Link suggestions"]');
    } else {
        // A narrow bar keeps only Tools, so the panel is found by typing there.
        $page->page()->locator(':focus')->type('link');
        cmWaitUntil($page, "Array.from(document.querySelectorAll('[data-slot=\"tool-row\"] [data-slot=\"tool-name\"]'), (button) => button.textContent.trim()).join() === 'Link suggestions'");
        $page->page()->locator(':focus')->press('Enter');
    }
    ($this->shown)($page, '2 links found', within: $this->panel);

    // The header names the panel and carries the plugin's mark; inside, sections say what they do.
    $page->assertSeeIn('[data-slot="pane-head"] h2', 'Link suggestions')
        ->assertScript("document.querySelector('[data-slot=\"pane-head\"]').textContent.includes('From Link Finder')")
        ->assertScript("Array.from(document.querySelectorAll('{$this->panel} h3'), (heading) => heading.textContent).join('|') === 'Ask|Suggestions'");

    expect($page->script("() => document.querySelector('{$this->panel}').innerText.includes('Link Finder')"))->toBeFalse()
        ->and(cmAxeViolations($page))->toBe([])
        ->and(($this->sideways)($page))->toBe(0);
})->with(['light', 'dark'])->with([1280, 375]);

it('runs an action and shows its job’s progress as it moves', function (): void {
    // Which plugin tools may be pinned depends on who is signed in.
    $this->actingAs($this->editor);
    lscrPrefer($this->editor, ['editor.pinned_tools' => ['assistant', 'share', 'plugin.linkfinder.links']]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $page->click('header button[aria-label="Link suggestions"]');
    ($this->shown)($page, '2 links found', within: $this->panel);

    $page->click('[data-test="panel-action-find"] button:text-is("Find links")');
    ($this->shown)($page, 'Waiting for a free worker', within: $this->panel);

    // The job starts and gets two of five done; the panel asks again on its own.
    DB::table('tracked_jobs')->update(['state' => JobState::Running->value, 'done' => 2, 'total' => 5]);
    ($this->shown)($page, '2 of 5', within: $this->panel);
    $page->assertPresent('[data-test="plugin-panel-job"][data-state="running"] [role="progressbar"]');
});

it('uses a title, a field, a link (and undoes it) and a whole article, and discards one', function (): void {
    // Which plugin tools may be pinned depends on who is signed in.
    $this->actingAs($this->editor);
    lscrPrefer($this->editor, ['editor.pinned_tools' => ['assistant', 'share', 'plugin.linkfinder.links']]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $page->click('header button[aria-label="Link suggestions"]');
    ($this->shown)($page, 'Link “feeding” to Feeding roses in spring', within: $this->panel);

    // A link: one change, which Undo takes back.
    $page->click('[data-test="panel-suggestion-link-1"] [data-test="panel-suggestion-use"]');
    cmWaitUntil($page, "document.querySelector('.teme-prose a')?.textContent === 'feeding'");
    ($this->shown)($page, 'Suggestion used as one change.');
    $page->click('Undo');
    cmWaitUntil($page, "document.querySelector('.teme-prose a') === null");

    // The title and a field.
    $page->click('[data-test="panel-suggestion-title"] [data-test="panel-suggestion-use"]');
    cmWaitUntil($page, "document.querySelector('#editor-title')?.value === 'Pruning roses in winter'");
    $page->click('[data-test="panel-suggestion-excerpt"] [data-test="panel-suggestion-use"]');
    $page->assertValue('#editor-excerpt', 'When and how to prune.');

    // Discarded: it goes, and the plugin hears it wasn't used.
    $page->click('[data-test="panel-suggestion-extra"] [data-test="panel-suggestion-discard"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"panel-suggestion-extra\"]') === null");

    // A whole article opens as a proposal first.
    $page->assertSeeIn('[data-test="panel-suggestion-body"] [data-test="panel-suggestion-use"]', 'Review the changes');
    $page->click('[data-test="panel-suggestion-body"] [data-test="panel-suggestion-use"]');
    $page->hover('[data-test="ai-use"]')
        ->assertSeeIn('[data-test="panel-suggestion-body"] ins', 'roses')
        ->click('[data-test="ai-use"]');
    ($this->shown)($page, 'Prune roses in late winter.', within: '.teme-prose');

    cmWaitUntil($page, "document.querySelector('[data-test=\"panel-suggestions\"]') === null");

    expect(Linkfinder::$settles)->toEqualCanonicalizing([
        ['link-1', true], ['title', true], ['excerpt', true], ['extra', false], ['body', true],
    ]);
});

it('says AI is switched off for the plugin, in place of its actions', function (): void {
    app(SettingsRegistry::class)->replace(app(AiSettings::class)->group());
    cmSettings([PluginAiSection::onKey('linkfinder') => false], AiSettings::KEY);
    lscrPrefer($this->editor, ['editor.pinned_tools' => ['assistant', 'share', 'plugin.linkfinder.links']]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $page->click('header button[aria-label="Link suggestions"]');
    ($this->shown)($page, 'AI switched off for Link Finder', within: $this->panel);

    $page->assertSeeIn($this->panel, 'An administrator switched it off in Settings › AI. The rest of Link Finder still works.')
        ->assertMissing('[data-test="panel-action-find"]');
});

it('says the plugin’s AI budget is used up, in place of its actions', function (): void {
    app(SettingsRegistry::class)->replace(app(AiSettings::class)->group());
    cmSettings([PluginAiSection::budgetKey('linkfinder') => 5], AiSettings::KEY);
    DB::table('ai_plugin_monthly_spend')->insert(['plugin' => 'linkfinder', 'month' => now()->utc()->startOfMonth()->format('Y-m-d'), 'cost_microunits' => 5_000_000, 'requests' => 3, 'unpriced_requests' => 0]);
    lscrPrefer($this->editor, ['editor.pinned_tools' => ['assistant', 'share', 'plugin.linkfinder.links']]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $page->click('header button[aria-label="Link suggestions"]');
    ($this->shown)($page, 'Link Finder’s budget used up', within: $this->panel);

    $page->assertSeeIn($this->panel, 'Its AI budget for this month is spent. It can ask AI again when the month ends.')
        ->assertMissing('[data-test="panel-action-find"]');
});

it('says the panel couldn’t be shown, and shows it again on Try again', function (): void {
    $broken = true;
    $view = Linkfinder::$view;
    Linkfinder::$view = function (mixed $context) use (&$broken, $view): PanelView {
        $broken ? throw new RuntimeException('broken') : null;

        return $view($context);
    };
    lscrPrefer($this->editor, ['editor.pinned_tools' => ['assistant', 'share', 'plugin.linkfinder.links']]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $page->click('header button[aria-label="Link suggestions"]');
    ($this->shown)($page, 'This panel couldn’t be shown this time.', within: $this->panel);
    $page->assertMissing('[data-test="panel-action-find"]');

    $broken = false;
    $page->click('[data-test="plugin-panel-failed"] button');
    ($this->shown)($page, '2 links found', within: $this->panel);
});
