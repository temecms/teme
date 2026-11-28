<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Editor\PanelPreview;
use Teme\Contracts\Editor\PanelRow;
use Teme\Contracts\Editor\PanelView;
use Tests\Fixtures\Plugins\Linkfinder\Linkfinder;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once __DIR__.'/../../Feature/Plugins/Editor/Linkfinder.php';

uses(RefreshDatabase::class);

// A plugin's panel shows its rows and its link card in the rail: the words the
// specification gives for a card with no picture, and the plain values of the rows.

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    Queue::fake();
    linkfinderEnabled();

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->post = docSave(lifecycleDraft($this->editor, 'Pruning roses'), $this->editor, docWith(docText('Prune in winter.')));
    $this->edit = "/admin/content/{$this->post->id}/edit";
    $this->panel = '[data-test="plugin-panel"]';

    // How far the page scrolls sideways: none.
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');

    Linkfinder::$view = fn (): PanelView => new PanelView(
        status: 'Ready',
        rows: [new PanelRow('Network', 'Mastodon'), new PanelRow('Reach so far', '1,240 views')],
        preview: new PanelPreview('Pruning roses in winter', 'When and how to prune.', null),
        rowsHeading: 'This article on the networks',
    );

    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

afterEach(function (): void {
    Linkfinder::reset();
    PluginFixtures::forget();
});

it('draws the rows, then the card with its note, and the no-picture words, with no accessibility issue', function (string $scheme, int $width): void {
    // Which plugin tools may be pinned depends on who is signed in.
    $this->actingAs($this->editor);
    lscrPrefer($this->editor, ['appearance.mode' => $scheme, 'editor.pinned_tools' => ['assistant', 'share', 'plugin.linkfinder.links']]);

    // A narrow bar keeps only Tools, so the panel is found by typing there.
    $page = cmNavigate(cmSignIn($this->editor)->resize($width, 900), $this->edit);
    $width > 640 ? cmEditorSettled($page) : cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");

    if ($width > 640) {
        $page->click('header button[aria-label="Link suggestions"]');
    } else {
        $page->click('header [data-test="editor-tools"]');
        cmWaitUntil($page, "document.activeElement?.getAttribute('type') === 'search' && document.activeElement.closest('[role=\"dialog\"]') !== null");
        $page->page()->locator(':focus')->type('link');
        cmWaitUntil($page, "Array.from(document.querySelectorAll('[data-slot=\"tool-row\"] [data-slot=\"tool-name\"]'), (button) => button.textContent.trim()).join() === 'Link suggestions'");
        $page->page()->locator(':focus')->press('Enter');
    }
    cmWaitUntil($page, "document.querySelector('[data-test=\"plugin-panel-rows\"]') !== null");

    $page->assertSeeIn($this->panel, 'This article on the networks')
        ->assertSeeIn('[data-test="plugin-panel-rows"]', 'Mastodon')
        ->assertSeeIn('[data-test="plugin-panel-rows"]', '1,240 views')
        ->assertSeeIn('[data-test="plugin-panel-preview"]', 'Pruning roses in winter')
        ->assertSeeIn('[data-test="plugin-panel-preview"]', 'This article has no featured image, so the card shows none.')
        ->assertSeeIn($this->panel, 'Shows the draft as you last saved it. It looks different on each network; this is the shape they share.')
        ->assertScript("document.querySelector('[data-test=\"plugin-panel-preview\"]').getAttribute('aria-label') === 'Preview of what Link Finder will send'")
        ->assertScript("Array.from(document.querySelectorAll('{$this->panel} h3'), (heading) => heading.textContent).join('|') === 'This article on the networks|How it will look|Ask'");

    expect(cmAxeViolations($page))->toBe([])
        ->and(($this->sideways)($page))->toBe(0);
})->with(['light', 'dark'])->with([1440, 375]);
