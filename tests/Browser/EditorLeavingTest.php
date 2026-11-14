<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

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

    // Waits until the editor has gone and the list it came from shows the item.
    $this->onList = fn (mixed $page): mixed => cmWaitUntil($page, "!location.pathname.endsWith('/edit') && document.body.innerText.includes('Harbour news')");

    // The person's own autosave of the draft, as stored.
    $this->autosaved = fn (): string => (string) DB::table('content_autosaves')->where('content_id', $this->draft->id)->where('user_id', $this->editor->id)->value('document');

    // Waits for words within one element.
    $this->textShown = fn (mixed $page, string $text, string $within): mixed => cmWaitUntil($page, '(document.querySelector('.json_encode($within, JSON_THROW_ON_ERROR).")?.innerText ?? '').includes(".json_encode($text, JSON_THROW_ON_ERROR).')');

    // Waits until the leave dialog is open and its own animations are over (.ai/rules/builders.md).
    $this->dialogOpen = fn (mixed $page): mixed => cmWaitUntil($page, "(() => { const dialog = document.querySelector('[data-test=\"leave-dialog\"]'); return dialog !== null && dialog.getAnimations().every((animation) => animation.playState !== 'running'); })()");

    // Holds every autosave in the page until the test lets them go: a stalled
    // connection, as the person sees it. The site runs in the test's own
    // process, so holding a request on the server would hold the test too.
    $this->holdSaves = fn (mixed $page): mixed => $page->script(<<<'JS'
        () => {
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            window.heldSaves = [];
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.heldUrl = String(url);
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (window.heldSaves !== null && this.heldUrl.includes('/autosave')) {
                    window.heldSaves.push(() => send.call(this, body));
                    return;
                }
                return send.call(this, body);
            };
        }
        JS);
    $this->releaseSaves = fn (mixed $page): mixed => $page->script('() => { const held = window.heldSaves; window.heldSaves = null; held.forEach((go) => go()); }');

    // Waits for a condition past the usual limit, for the held visit's own.
    $this->waitLong = function (mixed $page, string $condition): mixed {
        pest()->browser()->timeout(20_000);
        cmWaitUntil($page, $condition);
        pest()->browser()->timeout(5_000);

        return $page;
    };

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get(($this->edit)($this->draft))->assertOk();
});

afterEach(function (): void {
    pest()->browser()->timeout(5_000);
});

it('sends the words typed last when the person leaves by the way back before the autosave is due (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    // Well inside the two seconds an autosave waits after typing stops.
    $page->type('.teme-prose', 'The last ferry leaves at midnight.')
        ->click('header > a');

    ($this->onList)($page);

    // Nothing failed, so nothing asked.
    expect($page->script("() => document.querySelector('[data-test=\"leave-dialog\"]') === null"))->toBeTrue();

    cmEditorSettled($page->navigate(($this->edit)($this->draft)));
    cmWaitUntil($page, "document.querySelector('.teme-prose')?.innerText.includes('The last ferry leaves at midnight.') ?? false");

    expect(($this->autosaved)())->toContain('The last ferry leaves at midnight.');
});

it('keeps the last sentence of an article over 64 KB when the person leaves by the way back before the autosave is due (issue 6)', function (): void {
    // About 80 KB of text: more than a browser lets a keepalive request carry.
    $paragraphs = array_map(fn (int $n): array => docText("Paragraph {$n} of the long harbour history, told slowly so the article grows past what a keepalive request may carry."), range(1, 700));
    docSave($this->draft, $this->editor, docWith(...$paragraphs));

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    // At the end of the article, well inside the two seconds an autosave waits.
    $page->keys('.teme-prose', 'Control+End')
        ->typeSlowly('.teme-prose', ' The last ferry leaves at midnight.', 0)
        ->click('header > a');

    ($this->onList)($page);

    cmEditorSettled($page->navigate(($this->edit)($this->draft)));
    cmWaitUntil($page, "document.querySelector('.teme-prose')?.innerText.includes('The last ferry leaves at midnight.') ?? false");

    expect(strlen(($this->autosaved)()))->toBeGreaterThan(65536)
        ->and(($this->autosaved)())->toContain('The last ferry leaves at midnight.');
});

it('asks in the app\'s own dialog before a link leaves words no save has kept: Stay keeps the editor and its words, Leave goes (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    // The server fails every autosave from here on, on the site's own address. The test
    // can't route the browser's requests, so the app's own route answers 500 instead.
    Route::post('admin/content/{content}/autosave', fn () => response()->json(['message' => 'Server Error'], 500));

    $page->type('.teme-prose', 'The ferry is cancelled until spring.');

    cmWaitUntil($page, "['offline', 'refused'].includes(document.querySelector('[data-test=\"save-state\"]')?.dataset.state)");
    cmWaitUntil($page, "document.body.innerText.includes('The server couldn’t save your changes')");

    $page->click('header > a');

    ($this->dialogOpen)($page);
    ($this->textShown)($page, 'Your latest changes aren’t saved. Leave this page anyway?', '[data-test="leave-dialog"]');

    $page->click('[data-test="leave-dialog"] button:text-is("Stay")');

    cmWaitUntil($page, "document.querySelector('[data-test=\"leave-dialog\"]') === null");

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft))
        ->and($page->script("() => document.querySelector('.teme-prose').innerText"))->toContain('The ferry is cancelled until spring.');

    $page->click('header > a');

    ($this->dialogOpen)($page);
    $page->click('[data-test="leave-dialog"] button:text-is("Leave without saving")');

    ($this->onList)($page);
});

it('has the browser ask before an unload only while words are unsent, never for a saved article (issue 6)', function (): void {
    // Whether the page asks before it unloads: the event its own prompt answers.
    $asks = "() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; }";

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    expect($page->script($asks))->toBeFalse();

    $page->type('.teme-prose', 'The ferry leaves at dawn.');

    expect($page->script($asks))->toBeTrue();

    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'");

    expect($page->script($asks))->toBeFalse();

    // A reload of the saved article opens without asking, and asks nothing.
    cmEditorSettled($page->navigate(($this->edit)($this->draft)));

    expect($page->script($asks))->toBeFalse();
});

it('holds the browser\'s Back button while words are stored nowhere, and asks in the leave dialog (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    // To the list and back to the editor inside the app, so Back is a step in the app's own history.
    $page->click('header > a');
    ($this->onList)($page);
    $page->click('a >> text="Harbour news"');
    cmEditorSettled($page);

    Route::post('admin/content/{content}/autosave', fn () => response()->json(['message' => 'Server Error'], 500));

    $page->type('.teme-prose', 'The ferry is cancelled until spring.');

    cmWaitUntil($page, "['offline', 'refused'].includes(document.querySelector('[data-test=\"save-state\"]')?.dataset.state)");

    $page->script('() => history.back()');

    ($this->dialogOpen)($page);
    $page->click('[data-test="leave-dialog"] button:text-is("Stay")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"leave-dialog\"]') === null");

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft))
        ->and($page->script("() => document.querySelector('.teme-prose').innerText"))->toContain('The ferry is cancelled until spring.');

    $page->script('() => history.back()');

    ($this->dialogOpen)($page);
    $page->click('[data-test="leave-dialog"] button:text-is("Leave without saving")');

    ($this->onList)($page);
});

it('shows a save after the session has ended as signed out, not offline (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    // The session ends on the server: a day without a request is past every limit.
    $this->travel(1)->days();

    $page->type('.teme-prose', 'The ferry is cancelled until spring.');

    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'offline'");
    ($this->textShown)($page, 'Not saved: signed out', '[data-test="save-state"]');
    ($this->textShown)($page, 'You’ve been signed out', 'body');

    expect($page->script("() => document.querySelector('[data-test=\"save-state\"]').innerText"))->not->toContain('offline');
});

it('stops the presence poll once the session has ended, showing signed out and never the sign-in page (issue 6)', function (): void {
    // Asks every second, not every minute.
    config(['editor.presence_window' => 2]);

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    $this->travel(1)->days();

    ($this->textShown)($page, 'Not saved: signed out', '[data-test="save-state"]');

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft))
        ->and($page->script("() => document.querySelector('.teme-prose') !== null"))->toBeTrue();
});

it('asks in the leave dialog once a save held by a stalled connection passes its limit, and Stay keeps the words until the save lands (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    ($this->holdSaves)($page);

    $page->type('.teme-prose', 'The ferry waits for the tide.')
        ->click('header > a');

    // Within the limit: the editor stays, says a save is on its way, and asks nothing.
    cmWaitUntil($page, "window.heldSaves.length > 0 && document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saving'");

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft))
        ->and($page->script("() => document.querySelector('[data-test=\"leave-dialog\"]') === null"))->toBeTrue();

    // Past it: the app's own dialog asks, as after a failed save.
    ($this->waitLong)($page, "document.querySelector('[data-test=\"leave-dialog\"]') !== null");
    ($this->dialogOpen)($page);
    $page->click('[data-test="leave-dialog"] button:text-is("Stay")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"leave-dialog\"]') === null");

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft))
        ->and($page->script("() => document.querySelector('.teme-prose').innerText"))->toContain('The ferry waits for the tide.');

    // The save lands after all.
    ($this->releaseSaves)($page);
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'");

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft))
        ->and(($this->autosaved)())->toContain('The ferry waits for the tide.');
});

it('shows the error of a form sent from the editor while a save was on its way (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    // The server refuses the trash with a reason, as a validation error.
    Route::middleware('web')->post('admin/{type}/{content}/{action}', fn () => throw ValidationException::withMessages(['action' => 'The trash refused this item.']));

    ($this->holdSaves)($page);

    $page->type('.teme-prose', 'The pier is closed for repairs.');
    cmWaitUntil($page, 'window.heldSaves.length > 0');

    // The submission goes as it is, its own callbacks and all, while the save is still on its way.
    $page->click('button:text-is("Move to trash")');

    ($this->textShown)($page, 'The trash refused this item.', 'body');

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft));

    // The save is let go only now: the test's server shares one session store between requests.
    ($this->releaseSaves)($page);
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'");

    expect(($this->autosaved)())->toContain('The pier is closed for repairs.');
});

it('lets the browser\'s Back button go at once when everything is saved, and Forward come back to the editor (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    $page->click('header > a');
    ($this->onList)($page);
    $page->click('a >> text="Harbour news"');
    cmEditorSettled($page);

    // Typed and saved: nothing is left to send. The save is held only to see it go out and come back.
    ($this->holdSaves)($page);
    $page->type('.teme-prose', 'The harbour master signs off.');
    cmWaitUntil($page, 'window.heldSaves.length > 0');
    ($this->releaseSaves)($page);
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'");

    $page->script('() => history.back()');
    ($this->onList)($page);

    expect($page->script("() => document.querySelector('[data-test=\"leave-dialog\"]') === null"))->toBeTrue();

    $page->script('() => history.forward()');
    cmWaitUntil($page, "location.pathname.endsWith('/edit')");
    cmEditorSettled($page);

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft));
});

it('holds the browser\'s Back button while a save is on its way, then goes back with the words saved (issue 6)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->editor)->navigate(($this->edit)($this->draft)));

    $page->click('header > a');
    ($this->onList)($page);
    $page->click('a >> text="Harbour news"');
    cmEditorSettled($page);

    ($this->holdSaves)($page);

    $page->type('.teme-prose', 'The lighthouse keeper retires.');
    $page->script('() => history.back()');

    cmWaitUntil($page, "window.heldSaves.length > 0 && document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saving'");

    expect($page->script('() => location.pathname'))->toBe(($this->edit)($this->draft))
        ->and($page->script("() => document.querySelector('[data-test=\"leave-dialog\"]') === null"))->toBeTrue();

    ($this->releaseSaves)($page);
    ($this->onList)($page);

    expect(($this->autosaved)())->toContain('The lighthouse keeper retires.');
});
