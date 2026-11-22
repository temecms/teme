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

it('passes axe on the whole catalogue, every target 24 pixels or more and nothing scrolling sideways, at 1280 and 375 pixels in both schemes', function (string $scheme, int $width): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin)->resize($width, 900), '/admin/_catalogue');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue"]\') !== null');
    $page->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0)
        ->and($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();
})->with(['light', 'dark'])->with([1280, 375]);

it('moves through a radio group with the arrow keys, turns a switch on with Space, and presses one segment at a time', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue#radio-group');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-radio-weekly"]\') !== null');

    $page->click('[data-test="catalogue-radio-weekly"]');
    // Held as a person holds it: Radix checks the choice when focus arrives, a tick after the key goes down.
    $page->page()->locator('[data-test="catalogue-radio-weekly"]')->press('ArrowDown', ['delay' => 50]);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-radio-monthly"]\').getAttribute(\'aria-checked\') === \'true\' && document.activeElement === document.querySelector(\'[data-test="catalogue-radio-monthly"]\')');

    $page->keys('[data-test="catalogue-switch"]', 'Space');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-switch"]\').getAttribute(\'aria-checked\') === \'true\'');

    $page->click('[data-test="catalogue-segment-l"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-segment-l"]\').getAttribute(\'data-state\') === \'on\' && document.querySelector(\'[data-test="catalogue-segment-m"]\').getAttribute(\'data-state\') === \'off\'');
});

it('writes the accent button\'s words in a colour of their own, computed from the accent, in both schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue#button');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-button-primary"]\') !== null');

    // A relative colour the browser could not read would leave the words in the page's own ink.
    $page->assertScript('getComputedStyle(document.querySelector(\'[data-test="catalogue-button-primary"]\')).color !== getComputedStyle(document.body).color');
})->with(['light', 'dark']);

it('opens a dialog at each of the four sizes, and gives focus back to its opener when Escape closes it', function (string $size, string $width): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/_catalogue#dialog');
    cmWaitUntil($page, "document.querySelector('[data-test=\"catalogue-dialog-{$size}\"]') !== null");

    $page->click("[data-test=\"catalogue-dialog-{$size}\"]");
    shellSettled($page, '[data-slot="dialog-content"]');
    $page->assertScript("Math.round(document.querySelector('[data-slot=\"dialog-content\"]').getBoundingClientRect().width) === {$width}");

    $page->keys('[data-slot="dialog-content"]', 'Escape')
        ->assertNotPresent('[data-slot="dialog-content"]');
    cmWaitUntil($page, "document.activeElement === document.querySelector('[data-test=\"catalogue-dialog-{$size}\"]')");
})->with([
    'small' => ['sm', '480'],
    'medium' => ['md', '640'],
    'large' => ['lg', '860'],
    'the large fixed one' => ['xl', '1120'],
]);

it('moves between tabs with the arrow keys', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue#tabs');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-tab-library"]\') !== null');

    $page->click('[data-test="catalogue-tab-library"]')
        ->keys('[data-test="catalogue-tab-library"]', 'ArrowRight');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-tab-upload"]\').getAttribute(\'aria-selected\') === \'true\'');
});

it('reaches the last choice of a long select with the arrow keys, and shows it', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(1280, 900), '/admin/_catalogue#select');
    cmWaitUntil($page, 'document.querySelector(\'#catalogue-select-long\') !== null');

    $page->click('#catalogue-select-long');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="select-viewport"]\') !== null && document.querySelector(\'[data-slot="select-viewport"]\').scrollHeight > document.querySelector(\'[data-slot="select-viewport"]\').clientHeight');

    cmWaitUntil($page, 'document.activeElement?.getAttribute(\'role\') === \'option\'');

    // Radix moves focus a tick after each key, so each press waits for the one before it.
    foreach (range(2, 40) as $month) {
        $page->keys('[role="option"]:focus', 'ArrowDown');
        cmWaitUntil($page, "document.activeElement?.textContent === 'Month {$month}'");
    }

    // The list shows the design system's thin scrollbar, which Radix would hide.
    $page->assertScript('getComputedStyle(document.querySelector(\'[data-slot="select-viewport"]\')).scrollbarWidth === \'thin\'');

    // The focused choice sits inside the list's visible area.
    $page->assertScript('(() => { const list = document.querySelector(\'[data-slot="select-viewport"]\').getBoundingClientRect(); const item = document.activeElement.getBoundingClientRect(); return item.top >= list.top - 1 && item.bottom <= list.bottom + 1; })()');
});

it('opens the rest of a list of terms from its "+N" chip, which names them for a screen reader', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue#term-chips');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="term-chips-more"]\') !== null');

    $page->assertAttribute('[data-test="term-chips-more"]', 'aria-label', '3 more tags: Ferries, Port, Weather')
        ->click('[data-test="term-chips-more"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="popover-content"]\')?.innerText.includes("Weather") === true');
});

it('shows a loading skeleton only after 300 milliseconds, and a failed panel with a way to try again', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue#states');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-load"]\') !== null');

    $page->click('[data-test="catalogue-load"]')
        ->assertScript('document.querySelector(\'[data-test="catalogue-loading"] [data-slot="skeleton"]\') === null');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-loading"] [data-slot="skeleton"]\') !== null && document.querySelector(\'[data-test="catalogue-loading"]\').getAttribute(\'aria-busy\') === \'true\'');

    $page->assertAttribute('[data-test="catalogue-error"]', 'role', 'alert')
        ->assertSeeIn('[data-test="catalogue-error"]', 'Try again');
});

it('asks for the password, says a wrong one is wrong and keeps it, then lets the act go on', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue#fresh-password');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue-fresh-password"]\') !== null');

    $page->click('[data-test="catalogue-fresh-password"]');
    shellSettled($page, '[data-slot="dialog-content"]');
    $page->type('[data-test="fresh-password-input"]', 'not-the-password')
        ->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="fresh-password-error"]\') !== null && document.querySelector(\'[data-test="fresh-password-input"]\').value === "not-the-password"');

    $page->type('[data-test="fresh-password-input"]', 'password')
        ->click('[data-test="fresh-password-confirm"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-slot="dialog-content"]\') === null && document.querySelector(\'[data-test="catalogue-fresh-password-done"]\').innerText.includes("Confirmed")');
});

it('shows the save bar only once something changes, and hides it again on Discard', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue#save-bar');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\') !== null');

    $page->assertScript('document.querySelector(\'[data-test="save-bar"]\').hidden === true')
        ->type('#catalogue-save-bar-name', 'The Garden City Ledger');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === false');

    $page->click('[data-test="save-bar-discard"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === true && document.querySelector("#catalogue-save-bar-name").value === ""');
});

it('keeps the page patterns in their frames at 375 pixels, with one h1 each and nothing scrolling sideways', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 812), '/admin/_catalogue#patterns');
    cmWaitUntil($page, 'document.querySelectorAll(\'[data-test^="catalogue-pattern-"]\').length === 5');

    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->assertScript('[...document.querySelectorAll(\'[data-test^="catalogue-pattern-"]\')].every((frame) => frame.querySelectorAll(\'[data-test="pattern-title"]\').length === 1)');
});

it('keeps every catalogue section inside the column at 375 pixels', function (): void {
    $page = cmNavigate(cmSignIn($this->admin)->resize(375, 900), '/admin/_catalogue');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="catalogue"]\') !== null');

    $overflow = cmScriptArray($page, <<<'JS'
        () => {
            const root = document.querySelector('[data-test="catalogue"]');
            const column = Math.ceil(root.getBoundingClientRect().width);

            return Array.from(root.querySelectorAll('section[id]'), (section) => ({
                id: section.id,
                width: Math.round(section.getBoundingClientRect().width),
                scrollWidth: section.scrollWidth,
            })).filter((row) => row.scrollWidth > column + 1 || row.width > column + 1).map((row) => row.id);
        }
        JS);

    expect($overflow)->toBe([])
        ->and($page->script('() => document.querySelector(\'[data-test="catalogue"]\').scrollWidth <= document.querySelector(\'[data-test="catalogue"]\').clientWidth + 1'))->toBeTrue();
});

it('keeps a space between a required field\'s label and its "Required" (#503)', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/_catalogue');
    cmWaitUntil($page, 'document.querySelector(\'label[for="catalogue-input-error"]\') !== null');

    $label = cmScriptArray($page, <<<'JS'
        () => {
            const label = document.querySelector('label[for="catalogue-input-error"]');
            const mark = label.querySelector('span');
            const words = document.createRange();
            words.selectNodeContents(label.firstChild);

            return { text: label.innerText.trim(), gap: Math.round(mark.getBoundingClientRect().left - words.getBoundingClientRect().right) };
        }
        JS);

    expect($label['text'])->toBe('Address Required')
        ->and($label['gap'])->toBeGreaterThan(0);
});
