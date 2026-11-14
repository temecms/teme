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

    // The fields fill the column (less its 24-pixel sides), paragraphs keep the measure in the middle, the table and the image the full width.
    $this->fills = function (array $at): void {
        expect($at['title'])->toEqualWithDelta($at['column'] - 48, 1)
            ->and($at['body'])->toEqualWithDelta($at['column'] - 48, 1)
            ->and($at['section'])->toEqualWithDelta($at['column'] - 48, 1)
            ->and($at['paragraph'])->toEqualWithDelta(692, 1)
            ->and(abs($at['leftGap'] - $at['rightGap']))->toBeLessThanOrEqual(1.0)
            ->and($at['table'])->toEqualWithDelta($at['inner'], 1)
            ->and($at['image'])->toEqualWithDelta($at['inner'], 1)
            ->and($at['table'])->toBeGreaterThan(692)
            ->and($at['scrollsSideways'])->toBeFalse();
    };

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('fills the room between the menu and the side panel, paragraphs keeping the reading measure while the table and the image take the full width (I-3121)', function (int $width, int $height): void {
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
    $page->click('[data-slot="editor-shell"] > header [aria-label="Hide settings"]');
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

it('lets paragraphs give way on a narrow screen, as wide as the writing area allows and never wider, with nothing scrolling sideways (I-3121)', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1024, 768), $this->edit));
    ($this->drawn)($page);
    $at = ($this->measure)($page);

    expect($at['inner'])->toBeLessThan(692)
        ->and($at['paragraph'])->toEqualWithDelta($at['inner'], 1)
        ->and($at['table'])->toEqualWithDelta($at['inner'], 1)
        ->and($at['title'])->toEqualWithDelta($at['column'] - 48, 1)
        ->and($at['scrollsSideways'])->toBeFalse();
});
