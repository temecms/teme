<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    $this->panels = fn (mixed $page, string $part): string => $page->script("[...document.querySelectorAll('[data-test=\"settings-panel\"]')].map((panel) => panel.querySelector('".($part === 'title' ? 'h2' : '[data-test=\"settings-panel-description\"]')."')?.textContent.trim() ?? '').join('|')");
    $this->bar = fn (mixed $page): string => $page->script("document.querySelector('[data-test=\"save-bar\"] button[type=\"submit\"]').textContent.trim()");

    // Warm the screens before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('settings.discussion'))->assertOk();
    $this->actingAs($this->admin)->get('/admin/settings/general')->assertOk();
});

it('draws Settings › Discussion as titled panels, the switch acting at once and the rest saved from the one bar', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/settings/discussion');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 6")
        ->assertScript("[...document.querySelectorAll('form [data-test=\"settings-panel\"]')].every((panel) => panel.tagName === 'FIELDSET' && panel.querySelector(':scope > legend h2') !== null)")
        ->assertScript("document.querySelector('#comments-switch button[type=\"submit\"]') === null");

    expect(($this->panels)($page, 'title'))->toBe('Comments on this site|Where comments are open|Threads and pages|Moderation|Emails|Privacy')
        ->and(($this->panels)($page, 'description'))->toBe('The page stays when comments are off, because it holds the switch.|||Which comments wait for someone to approve them.||')
        ->and(($this->bar)($page))->toBe('Save');

    $page->type('#field-thread_depth', '4');
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-bar\"]').hidden === false && (() => { const gap = window.innerHeight - document.querySelector('[data-test=\"save-bar\"]').getBoundingClientRect().bottom; return gap >= 15 && gap <= 17; })()");

    // To the page's end, past the form's: the floating bar rides 16 above the window's foot.
    $page->script('window.scrollTo(0, document.documentElement.scrollHeight)');

    cmWaitUntil($page, "(() => { const bar = document.querySelector('[data-test=\"save-bar\"]').getBoundingClientRect(); const form = document.querySelector('[data-test=\"save-bar\"]').closest('form').getBoundingClientRect(); return Math.abs(bar.bottom - form.bottom) <= 1; })()");
});

it('puts each switch beside its words with the hint under them, and each number in a 128px box with its unit (the approved design)', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/settings/discussion');

    cmWaitUntil($page, "document.querySelector('#field-hold_all') !== null")
        ->assertScript("(() => { const box = document.querySelector('#field-hold_all').getBoundingClientRect(); const label = document.querySelector('label[for=\"field-hold_all\"]').getBoundingClientRect(); const hint = document.querySelector('#field-hold_all-hint').getBoundingClientRect(); return box.right <= label.left && Math.abs(box.top - label.top) <= 4 && hint.top >= label.bottom - 1 && hint.left >= label.left - 1; })()")
        ->assertScript("Math.round(document.querySelector('#field-thread_depth').getBoundingClientRect().width)", 128)
        ->assertScript("document.querySelector('#field-thread_depth').parentElement.querySelector('[data-test=\"field-unit\"]').textContent.trim()", 'levels')
        ->assertScript("document.querySelector('#field-keep_ip_days').parentElement.querySelector('[data-test=\"field-unit\"]').textContent.trim()", 'days')
        ->assertScript("document.querySelector('#field-per_page').parentElement.querySelector('[data-test=\"field-unit\"]').textContent.trim()", 'comments');
});

it('saves the five sections from the bar', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/settings/discussion');

    cmWaitUntil($page, "document.querySelector('#field-thread_depth') !== null")
        ->type('#field-thread_depth', '4')
        ->click('[data-test="save-bar"] button[type="submit"]');

    cmWaitUntil($page, "document.body.innerText.includes('Settings saved.')");

    expect(app(SettingsReader::class)->int('discussion', 'thread_depth'))->toBe(4);
});

it('splits Settings › General into Site, Contact, Time and language and Brand colour, saved by the one bar after them', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/settings/general');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 4")
        ->assertScript("document.querySelector('[data-test=\"brand-colour\"] button[type=\"submit\"]') === null");

    expect(($this->panels)($page, 'title'))->toBe('Site|Contact|Time and language|Brand colour')
        ->and(($this->bar)($page))->toBe('Save');

    $page->type('#field-site_title', 'The Garden City Ledger');
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-bar\"]').hidden === false");
    $page->script("document.querySelector('[data-test=\"save-bar\"]').closest('form').scrollIntoView({ block: 'end' })");

    cmWaitUntil($page, "document.querySelector('[data-test=\"save-bar\"]').getBoundingClientRect().top >= document.querySelector('[data-test=\"brand-colour\"]').getBoundingClientRect().top");
});

it('stacks the panels on a phone with nothing scrolling sideways', function (): void {
    $page = cmSignIn($this->admin)->resize(390, 844)->navigate('/admin/settings/discussion');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 6")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("(() => { const [first, second] = [...document.querySelectorAll('[data-test=\"settings-panel\"]')].map((panel) => panel.getBoundingClientRect()); return Math.round(first.left) === Math.round(second.left) && second.top >= first.bottom; })()");
});

it('passes axe on Discussion and General, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->navigate('/admin/settings/discussion');
    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 6");
    $page->assertNoAccessibilityIssues(3);
    $discussion = cmSmallTargets($page);

    $page->navigate('/admin/settings/general');
    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 4");
    $page->assertNoAccessibilityIssues(3);
    $general = cmSmallTargets($page);

    expect($discussion['small'])->toBe([])
        ->and($discussion['checked'])->toBeGreaterThan(0)
        ->and($general['small'])->toBe([])
        ->and($general['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('gives each page one save bar, its button saying Save', function (string $path, string $label): void {
    $page = cmSignIn($this->admin)->navigate($path);

    cmWaitUntil($page, "document.querySelector('[data-test=\"save-bar\"]') !== null && document.querySelectorAll('[data-test=\"settings-panel\"]').length > 0")
        ->assertScript("[...document.querySelectorAll('[data-test=\"save-bar\"]')].length", 1);

    expect(($this->bar)($page))->toBe($label);
})->with([
    'Writing' => ['/admin/settings/writing', 'Save'],
    'Reading' => ['/admin/settings/reading', 'Save'],
    'Media' => ['/admin/settings/media', 'Save'],
    'People' => ['/admin/settings/people', 'Save'],
    'Search and social' => ['/admin/settings/seo', 'Save'],
    'Addresses' => ['/admin/settings/addresses', 'Save'],
    'AI' => ['/admin/settings/ai', 'Save'],
]);

it('draws each section of every other settings page as a panel under its built heading', function (string $path, string $first): void {
    $page = cmSignIn($this->admin)->navigate($path);

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length > 0")
        ->assertScript("document.querySelector('[data-test=\"settings-panel\"] h2')?.textContent.trim() ?? ''", $first)
        ->assertScript("[...document.querySelectorAll('[data-test=\"settings-panel\"]')].every((panel) => panel.querySelector('h2') === null || panel.querySelector('h2').textContent.trim() !== '')")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
})->with([
    'People' => ['/admin/settings/people', 'Passwords'],
    'Search and social' => ['/admin/settings/seo', 'Titles in search results'],
    'AI' => ['/admin/settings/ai', 'AI services'],
    'Site code' => ['/admin/settings/site-code', 'Code on every page'],
]);
