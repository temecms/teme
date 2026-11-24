<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    $cell = fn (string $type, string $text): array => ['type' => $type, 'attrs' => [], 'content' => [docText($text)]];
    $row = fn (string $type, string ...$texts): array => ['type' => 'tableRow', 'content' => array_map(fn (string $text): array => $cell($type, $text), $texts)];

    // A paragraph long enough to fill the measure, a four-column table, an image card, and another paragraph.
    $this->draft = docSave(lifecycleDraft($this->editor, 'Cleaning hiking boots'), $this->editor, docWith(
        docText(trim(str_repeat('Mud does more than make your boots look rough after a day on the trail. ', 6))),
        ['type' => 'table', 'content' => [
            $row('tableHeader', 'Material', 'Best cleaning method', 'Drying approach', 'Waterproofing'),
            $row('tableCell', 'Full-grain leather', 'Leather cleaner and a soft brush', 'Air dry, stuffed with newspaper', 'While still damp'),
        ]],
        pageImageNode(pageImage($this->editor, 1600, 900), (string) Str::ulid()),
        docText(trim(str_repeat('Dry them away from heat, and give them a full day. ', 6))),
    ));
    $this->edit = "/admin/content/{$this->draft->id}/edit";

    // The menu, collapsed or expanded, with its width transition over (EditorWorkspaceTest).
    $this->menuIs = fn (mixed $page, string $state): mixed => cmWaitUntil($page, "document.querySelector('[data-slot=\"sidebar\"]')?.dataset.state === '{$state}' && document.getAnimations().every((animation) => !(animation instanceof CSSTransition) || animation.playState !== 'running')");

    // Waits until the table and the image card are drawn.
    $this->drawn = fn (mixed $page): mixed => cmWaitUntil($page, "document.querySelector('.teme-prose > .tableWrapper') !== null && document.querySelector('.teme-prose > .node-image') !== null");

    // The writing area's parts, in pixels: the column, the fields in it, the prose's own content box, and each block.
    $this->measure = fn (mixed $page): array => cmScriptArray($page, <<<'JS'
        () => {
            const width = (selector) => { const element = document.querySelector(selector); return element === null ? null : element.getBoundingClientRect().width; };
            const writing = document.querySelector('[data-slot="editor-writing"]');
            const prose = document.querySelector('.teme-prose');
            const style = getComputedStyle(prose);
            const inner = prose.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            const innerLeft = prose.getBoundingClientRect().left + prose.clientLeft + parseFloat(style.paddingLeft);
            const paragraph = document.querySelector('.teme-prose > p').getBoundingClientRect();

            return {
                column: writing.clientWidth,
                titleLeft: document.querySelector('#editor-title').getBoundingClientRect().left,
                paragraphLeft: paragraph.left,
                toolbarLeft: document.querySelector('[role="toolbar"]').getBoundingClientRect().left,
                title: width('#editor-title'),
                body: width('[data-slot="editor-body"]'),
                section: width('[data-test="search-section"]'),
                inner,
                paragraph: paragraph.width,
                leftGap: paragraph.left - innerLeft,
                rightGap: innerLeft + inner - paragraph.right,
                table: width('.teme-prose > .tableWrapper'),
                image: width('.teme-prose > .node-image'),
                scrollsSideways: writing.scrollWidth > writing.clientWidth,
            };
        }
        JS);

    // One column, at most the 704-pixel measure (less the 40-pixel sides on a narrower one): the title, the toolbar and the text share its left edge; the table and the image take its width.
    $this->fills = function (array $at): void {
        $sheet = min(704, $at['column'] - 80);

        expect($at['title'])->toEqualWithDelta($sheet, 1)
            ->and($at['paragraph'])->toEqualWithDelta($sheet, 1)
            ->and($at['paragraphLeft'])->toEqualWithDelta($at['titleLeft'], 1)
            ->and($at['toolbarLeft'])->toEqualWithDelta($at['titleLeft'], 1)
            ->and($at['table'])->toEqualWithDelta($at['inner'], 1)
            ->and($at['image'])->toEqualWithDelta($at['inner'], 1)
            ->and($at['scrollsSideways'])->toBeFalse();
    };

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('keeps the side panel and everything in it inside the window for a published post with a long address (#718)', function (int $width, int $height): void {
    $post = addrPublished($this->editor, 'Tennis Shots Explained: 18 Techniques to Improve Your Game and Win More Points Every Season');
    $this->actingAs($this->editor)->get("/admin/content/{$post->id}/edit")->assertOk();

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize($width, $height), "/admin/content/{$post->id}/edit"));
    cmWaitUntil($page, "document.querySelector('aside[aria-label=\"Post settings\"] [data-test=\"publish-section\"]') !== null");

    $at = cmScriptArray($page, <<<'JS'
        () => {
            const aside = document.querySelector('aside[aria-label="Post settings"]');
            const parts = [...aside.querySelectorAll('button, input, [role="combobox"], a')].filter((element) => element.getClientRects().length > 0);

            return {
                window: document.documentElement.clientWidth,
                aside: aside.getBoundingClientRect().right,
                widest: Math.max(...parts.map((element) => element.getBoundingClientRect().right)),
                overflows: aside.scrollWidth > aside.clientWidth + 1,
            };
        }
        JS);

    expect($at['aside'])->toBeLessThanOrEqual($at['window'] + 0.5)
        ->and($at['widest'])->toBeLessThanOrEqual($at['aside'] + 0.5)
        ->and($at['overflows'])->toBeFalse();
})->with([
    '1440 × 900' => [1440, 900],
    '1920 × 928' => [1920, 928],
]);

it('keeps one writing column at the reading measure, the title, the toolbar and the text on one left edge, whether the menu or the side panel is open', function (int $width, int $height): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize($width, $height), $this->edit));
    ($this->drawn)($page);

    // As the editor opens: the menu folded, the side panel open.
    $folded = ($this->measure)($page);
    ($this->fills)($folded);

    // The menu unfolded by hand: its room comes out of the writing area, never out of the measure.
    $page->click('[data-test="collapse-menu"]');
    ($this->menuIs)($page, 'expanded');
    $open = ($this->measure)($page);
    ($this->fills)($open);

    // Folded again and the side panel closed: the writing area takes the panel's room too.
    $page->click('[data-test="collapse-menu"]');
    ($this->menuIs)($page, 'collapsed');
    $page->click('[data-test="rail-toggle"]');
    cmWaitUntil($page, "document.querySelector('aside[aria-label=\"Post settings\"]') === null");
    $closed = ($this->measure)($page);
    ($this->fills)($closed);

    // The sidebar open and folded to its rail: 264 and 68 (docs/architecture/design-system.md, section 2.9).
    expect($folded['column'] - $open['column'])->toEqualWithDelta(264 - 68, 2)
        ->and($closed['column'] - $folded['column'])->toEqualWithDelta(360, 2);
})->with([
    '1440 × 900' => [1440, 900],
    '1920 × 1080' => [1920, 1080],
]);

it('lets paragraphs give way on a narrow screen, as wide as the writing column allows and never wider, on the title\'s left edge, with nothing scrolling sideways', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1024, 768), $this->edit));
    ($this->drawn)($page);
    $at = ($this->measure)($page);

    expect($at['inner'])->toBeLessThan(704)
        ->and($at['paragraph'])->toEqualWithDelta($at['inner'], 1)
        ->and($at['table'])->toEqualWithDelta($at['inner'], 1)
        ->and($at['title'])->toEqualWithDelta($at['column'] - 80, 1)
        ->and($at['paragraphLeft'])->toEqualWithDelta($at['titleLeft'], 1)
        ->and($at['scrollsSideways'])->toBeFalse();
});

it('grows the title with its lines and never gives it a scrollbar', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1024, 768), $this->edit));
    ($this->drawn)($page);

    $page->fill('#editor-title', 'How Much Does It Cost to Build a House in Hawaii? A Full Cost Breakdown for Every Island, Every Budget, and Every Kind of Buyer Who Is Planning Ahead');
    cmWaitUntil($page, "document.querySelector('#editor-title').value.length > 100");

    $at = cmScriptArray($page, <<<'JS'
        () => {
            const title = document.querySelector('#editor-title');
            const line = parseFloat(getComputedStyle(title).lineHeight);

            return {
                overflowY: getComputedStyle(title).overflowY,
                lines: Math.round(title.clientHeight / line),
                clipped: title.scrollHeight - title.clientHeight,
            };
        }
        JS);

    expect($at['lines'])->toBeGreaterThanOrEqual(2)
        ->and($at['overflowY'])->toBe('hidden')
        ->and($at['clipped'])->toBeLessThanOrEqual(2);
});
