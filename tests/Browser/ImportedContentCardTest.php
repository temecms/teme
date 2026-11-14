<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs the built styles and the admin's script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->card = icFixture()['content'][0];
    // The node types of the editor's last autosave of this item.
    $this->autosaved = fn (string $id): array => array_column(json_decode((string) DB::table('content_autosaves')->where('content_id', $id)->value('document'), true)['content'] ?? [], 'type');
    // The save state reads "Saved …"; a plain assertSee would match "Unsaved changes".
    $this->saved = "[...document.querySelectorAll('[role=\"status\"]')].some((element) => element.textContent.trim().startsWith('Saved '))";
    $this->openDraft = function (): array {
        $draft = lifecycleDraft($this->editor, 'Tide tables');
        docSave($draft, $this->editor, docWith(docText('This week’s tides.'), $this->card));
        // Warm the screen before the browser's first visit (I-638).
        $this->actingAs($this->editor)->get("/admin/content/{$draft->id}/edit")->assertOk();

        return [$draft, cmSignIn($this->editor)->navigate("/admin/content/{$draft->id}/edit")];
    };
    // A menu in the header's and the footer's slots: the shell's empty slots fail axe's landmark-unique.
    $this->bindMenus = function (): void {
        $about = addrPublished($this->editor, 'About', 'page');

        foreach (['primary', 'footer'] as $slot) {
            $menu = navCreate($this->editor, ucfirst($slot));
            navSave($this->editor, $menu, [['kind' => 'content', 'target_id' => $about->id]]);
            app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, (string) $this->editor->id));
        }
    };
});

it('passes axe on an article holding imported content, in the light scheme (I-329)', function (): void {
    ($this->bindMenus)();
    pageArticleFromDocument($this->editor, 'Tide tables', [docText('This week’s tides.'), $this->card]);

    visit('/tide-tables/')
        ->assertSeeIn('.card-imported caption', 'High water, this week')
        ->assertNoAccessibilityIssues(3);
});

it('passes axe on the same article in the dark scheme (I-329)', function (): void {
    ($this->bindMenus)();
    pageArticleFromDocument($this->editor, 'Tide tables', [docText('This week’s tides.'), $this->card]);
    designPublish(['theme.default' => ['color_scheme' => 'dark']]);

    visit('/tide-tables/')->assertNoAccessibilityIssues(3);
});

it('shows the locked box with its words, keeps it, then converts it to paragraphs the autosave holds (I-329)', function (): void {
    [$draft, $page] = ($this->openDraft)();

    $page->assertSeeIn('.node-imported', 'Imported from WordPress: a table Teme couldn’t convert')
        ->assertSeeIn('.node-imported', 'It came from the [table] shortcode, as its plugin drew it on the old site.')
        ->assertSeeIn('.node-imported [role="region"]', 'High water, this week')
        ->assertSeeIn('.node-imported', 'Needs a decision')
        ->click('.node-imported button:text-is("Keep as it is")')
        ->assertSeeIn('.node-imported', 'Kept as readers see it. The import’s report no longer lists it.')
        ->assertNotPresent('.node-imported button:text-is("Keep as it is")')
        ->click('.node-imported button:text-is("Convert to paragraphs")')
        ->assertNotPresent('.node-imported')
        ->assertSeeIn('.tiptap', 'Day | Time')
        ->assertScript($this->saved, true);

    retry(20, fn () => expect(($this->autosaved)($draft->id))->not->toContain('imported')->toContain('paragraph'), 250);
});

it('deletes the box, and the autosave holds the article without it (I-329)', function (): void {
    [$draft, $page] = ($this->openDraft)();

    $page->click('.node-imported button[aria-label="Delete imported content"]')
        ->assertNotPresent('.node-imported')
        ->assertSeeIn('.tiptap', 'This week’s tides.')
        ->assertScript($this->saved, true);

    // The editor keeps an empty paragraph after a last card it no longer has: the article's own paragraph, then that one.
    retry(20, fn () => expect(($this->autosaved)($draft->id))->toBe(['paragraph', 'paragraph']), 250);
});
