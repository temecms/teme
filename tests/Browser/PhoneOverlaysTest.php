<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screen before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/_catalogue')->assertOk();
});

/**
 * The overlay's box and the window's size, rounded to whole pixels. The
 * window's size is the area a fixed overlay is placed in: the admin keeps the
 * scrollbar's gutter (I-3130), which a phone's own scrollbar never takes.
 *
 * @return array{top: int, bottom: int, left: int, width: int, height: int, vw: int, vh: int}
 */
function poBox(mixed $page, string $selector): array
{
    $quoted = json_encode($selector, JSON_THROW_ON_ERROR);

    /** @var array{top: int, bottom: int, left: int, width: int, height: int, vw: int, vh: int} */
    return cmScriptArray($page, <<<JS
        () => { const r = document.querySelector({$quoted}).getBoundingClientRect();
          const probe = document.createElement('div');
          probe.style.cssText = 'position: fixed; inset: 0; visibility: hidden; pointer-events: none';
          document.body.append(probe);
          const w = probe.getBoundingClientRect();
          probe.remove();
          return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left),
                   width: Math.round(r.width), height: Math.round(r.height), vw: Math.round(w.width), vh: Math.round(w.height) }; }
        JS, 'poBox');
}

/** Signs in and opens the catalogue's phone overlays at the given size. */
function poCatalogue(User $person, int $width, int $height = 900): mixed
{
    $page = cmNavigate(cmSignIn($person)->resize($width, $height), '/admin/_catalogue#phone-overlays');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-overlay-dialog"]\') !== null');

    return $page;
}

/** Opens one of the catalogue's overlays with its button, and waits until it has settled. */
function poOpen(mixed $page, string $name): mixed
{
    $page->click("[data-test=\"{$name}\"]");

    return shellSettled($page, "[data-test=\"{$name}-content\"]");
}

/** Closes the overlay open on the page with Escape, and waits until it has gone. */
function poClose(mixed $page, string $name): mixed
{
    $page->keys("[data-test=\"{$name}-content\"]", 'Escape');

    return cmWaitUntil($page, "document.querySelector('[data-test=\"{$name}-content\"]') === null");
}

it('fills a phone\'s screen with a dialog, its title and Save in view as the body scrolls', function (): void {
    $page = poOpen(poCatalogue($this->admin, 375, 667), 'catalogue-overlay-dialog');

    $box = poBox($page, '[data-test="catalogue-overlay-dialog-content"]');

    expect([$box['top'], $box['left'], $box['width'], $box['bottom']])->toBe([0, 0, $box['vw'], $box['vh']]);

    $page->assertScript('(() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); return body.scrollHeight > body.clientHeight; })()');

    // Save is in view before the body scrolls, the title after it.
    expect(poBox($page, '[data-test="catalogue-overlay-dialog-save"]')['bottom'])->toBeLessThanOrEqual($box['vh']);

    $page->script('() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); body.scrollTop = body.scrollHeight; }');
    cmWaitUntil($page, '(() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); return body.scrollTop > 0 && body.scrollTop + body.clientHeight >= body.scrollHeight - 1; })()');

    $title = poBox($page, '[data-test="catalogue-overlay-dialog-content"] [data-slot="dialog-title"]');
    $save = poBox($page, '[data-test="catalogue-overlay-dialog-save"]');

    expect($title['top'] - $box['top'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(16)
        ->and($save['bottom'])->toBeLessThanOrEqual($box['vh']);
});

it('keeps the dialog centered at its width from 640 up', function (): void {
    $page = poOpen(poCatalogue($this->admin, 1280, 900), 'catalogue-overlay-dialog');

    $box = poBox($page, '[data-test="catalogue-overlay-dialog-content"]');

    expect($box['width'])->toBe(640)
        ->and(abs($box['left'] - intdiv($box['vw'] - 640, 2)))->toBeLessThanOrEqual(1)
        ->and($box['top'])->toBeGreaterThan(0)
        ->and($box['bottom'])->toBeLessThan($box['vh']);

    // The window's new height reaches the dialog's cap a few frames after the resize.
    $page->resize(1280, 600);
    cmWaitUntil($page, 'innerHeight === 600 && document.querySelector(\'[data-test="catalogue-overlay-dialog-content"]\').getBoundingClientRect().height <= innerHeight - 48');

    $box = poBox($page, '[data-test="catalogue-overlay-dialog-content"]');

    expect($box['height'])->toBeLessThanOrEqual($box['vh'] - 48);
    $page->assertScript('(() => { const body = document.querySelector(\'[data-test="catalogue-overlay-dialog-content"] [data-slot="responsive-dialog-body"]\'); return body.scrollHeight > body.clientHeight; })()');
});

it('opens a confirmation from the foot of a phone\'s screen', function (): void {
    $page = poOpen(poCatalogue($this->admin, 375, 667), 'catalogue-overlay-confirm');

    $box = poBox($page, '[data-test="catalogue-overlay-confirm-content"]');

    expect([$box['bottom'], $box['left'], $box['width']])->toBe([$box['vh'], 0, $box['vw']])
        ->and($box['top'])->toBeGreaterThan(0);

    $page->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderTopLeftRadius !== "0px"')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderTopRightRadius !== "0px"')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderBottomLeftRadius', '0px')
        ->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-overlay-confirm-content"]\')).borderBottomRightRadius', '0px')
        ->assertScript('document.activeElement === document.querySelector(\'[data-test="catalogue-overlay-confirm-cancel"]\')');

    poClose($page, 'catalogue-overlay-confirm');
    cmWaitUntil($page, 'document.activeElement === document.querySelector(\'[data-test="catalogue-overlay-confirm"]\')');
});

it('fills a phone\'s screen with a side sheet at 600 pixels', function (): void {
    $page = poOpen(poCatalogue($this->admin, 600, 800), 'catalogue-overlay-sheet');

    $box = poBox($page, '[data-test="catalogue-overlay-sheet-content"]');

    expect($box['width'])->toBe($box['vw']);

    poClose($page, 'catalogue-overlay-sheet');
    $page->resize(1280, 900);
    cmWaitUntil($page, 'innerWidth === 1280');
    poOpen($page, 'catalogue-overlay-sheet');

    expect(poBox($page, '[data-test="catalogue-overlay-sheet-content"]')['width'])->toBe(480);
});

it('passes axe with each overlay open, every target 24 pixels or more, at 375 and 1280 in both schemes', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = poCatalogue($this->admin, $width);

    foreach (['catalogue-overlay-dialog', 'catalogue-overlay-confirm', 'catalogue-overlay-sheet'] as $overlay) {
        poOpen($page, $overlay);

        $targets = cmSmallTargets($page);

        expect(cmAxeViolations($page))->toBe([], "axe with {$overlay} open")
            ->and($targets['small'])->toBe([], "targets with {$overlay} open")
            ->and($targets['checked'])->toBeGreaterThan(0);

        poClose($page, $overlay);
    }
})->with(['light', 'dark'])->with([375, 1280]);

it('scrolls nothing sideways at 320 with the dialog open', function (): void {
    $page = poOpen(poCatalogue($this->admin, 320, 640), 'catalogue-overlay-dialog');

    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth');
});
