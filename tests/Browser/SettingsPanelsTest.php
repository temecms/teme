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
    $this->bar = fn (mixed $page): array => cmScriptArray($page, "(() => { const bar = document.querySelector('[data-test=\"save-bar\"]'); return { label: bar.querySelector('button[type=\"submit\"]').textContent.trim(), note: bar.querySelector('[data-test=\"save-bar-note\"]')?.textContent.trim() ?? '' }; })()");

    // Warm the screens before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('settings.discussion'))->assertOk();
    $this->actingAs($this->admin)->get('/admin/settings/general')->assertOk();
});

it('draws Settings › Discussion as titled panels, the switch with its own Save and the rest saved from a bar at the foot of the window (I-3128)', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/settings/discussion');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 6")
        ->assertScript("[...document.querySelectorAll('form [data-test=\"settings-panel\"]')].every((panel) => panel.tagName === 'FIELDSET' && panel.querySelector(':scope > legend h2') !== null)")
        ->assertScript("document.querySelector('#comments-switch [data-test=\"panel-save\"] button[type=\"submit\"]').textContent.trim()", 'Save the comments switch')
        ->assertScript("document.querySelector('#comments-switch [data-test=\"panel-save\"]').textContent.includes('Saves this switch only.')")
        ->assertScript("Math.abs(document.querySelector('[data-test=\"save-bar\"]').getBoundingClientRect().bottom - window.innerHeight) <= 1");

    expect(($this->panels)($page, 'title'))->toBe('Comments on this site|Where readers can comment|Threads|Before a comment appears|Emails|Commenters’ details')
        ->and(($this->panels)($page, 'description'))->toBe('Comments are an optional part of Teme, switched on or off for the whole site.|Which new items take comments, and who may write them.|How deep replies go, and how many threads each page shows.|Which comments wait for approval, and which go straight to spam.|Who is told about new comments.|What Teme keeps about the people who comment, and for how long.')
        ->and(($this->bar)($page))->toBe(['label' => 'Save discussion settings', 'note' => 'Saves every section below the comments switch.']);

    $page->script("document.querySelector('[data-test=\"save-bar\"]').closest('form').scrollIntoView({ block: 'end' })");

    cmWaitUntil($page, "(() => { const bar = document.querySelector('[data-test=\"save-bar\"]').getBoundingClientRect(); const form = document.querySelector('[data-test=\"save-bar\"]').closest('form').getBoundingClientRect(); return Math.abs(bar.bottom - form.bottom) <= 1; })()");
});

it('puts each checkbox beside its words with the hint under them, and each number in a narrow box with its unit', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/settings/discussion');

    cmWaitUntil($page, "document.querySelector('#field-hold_all') !== null")
        ->assertScript("(() => { const box = document.querySelector('#field-hold_all').getBoundingClientRect(); const label = document.querySelector('label[for=\"field-hold_all\"]').getBoundingClientRect(); const hint = document.querySelector('#field-hold_all-hint').getBoundingClientRect(); return box.right <= label.left && Math.abs(box.top - label.top) <= 4 && hint.top >= label.bottom - 1 && hint.left >= label.left - 1; })()")
        ->assertScript("Math.round(document.querySelector('#field-thread_depth').getBoundingClientRect().width)", 88)
        ->assertScript("document.querySelector('#field-thread_depth').parentElement.querySelector('[data-test=\"field-unit\"]').textContent.trim()", 'levels')
        ->assertScript("document.querySelector('#field-keep_ip_days').parentElement.querySelector('[data-test=\"field-unit\"]').textContent.trim()", 'days')
        ->assertScript("document.querySelector('#field-per_page').parentElement.querySelector('[data-test=\"field-unit\"]') === null");
});

it('saves the five sections from the bar', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/settings/discussion');

    cmWaitUntil($page, "document.querySelector('#field-thread_depth') !== null")
        ->type('#field-thread_depth', '4')
        ->click('[data-test="save-bar"] button[type="submit"]');

    cmWaitUntil($page, "document.body.innerText.includes('Settings saved.')");

    expect(app(SettingsReader::class)->int('discussion', 'thread_depth'))->toBe(4);
});

it('splits Settings › General into The site and Time and language, Brand colour keeping its own Save, and the bar ending before it', function (): void {
    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/settings/general');

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 3")
        ->assertScript("document.querySelector('[data-test=\"brand-colour\"] button[type=\"submit\"]').textContent.trim()", 'Save brand colour');

    expect(($this->panels)($page, 'title'))->toBe('The site|Time and language|Brand colour')
        ->and(($this->bar)($page))->toBe(['label' => 'Save general settings', 'note' => 'Saves The site, and Time and language. Brand colour has its own Save.']);

    $page->script("document.querySelector('[data-test=\"brand-colour\"]').scrollIntoView({ block: 'end' })");

    cmWaitUntil($page, "document.querySelector('[data-test=\"save-bar\"]').getBoundingClientRect().bottom <= document.querySelector('[data-test=\"brand-colour\"]').getBoundingClientRect().top + 1");
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
    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length === 3");
    $page->assertNoAccessibilityIssues(3);
    $general = cmSmallTargets($page);

    expect($discussion['small'])->toBe([])
        ->and($discussion['checked'])->toBeGreaterThan(0)
        ->and($general['small'])->toBe([])
        ->and($general['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('labels each page\'s Save for its page, and says what it saves only where the page has another Save', function (string $path, string $label, string $note): void {
    $page = cmSignIn($this->admin)->navigate($path);

    cmWaitUntil($page, "document.querySelector('[data-test=\"save-bar\"]') !== null && document.querySelectorAll('[data-test=\"settings-panel\"]').length > 0")
        ->assertScript("[...document.querySelectorAll('[data-test=\"save-bar\"]')].length", 1);

    expect(($this->bar)($page))->toBe(['label' => $label, 'note' => $note]);
})->with([
    'Writing' => ['/admin/settings/writing', 'Save writing settings', ''],
    'Reading' => ['/admin/settings/reading', 'Save reading settings', 'Saves the settings above. Home page has its own Save.'],
    'Media' => ['/admin/settings/media', 'Save media settings', ''],
    'People' => ['/admin/settings/people', 'Save people settings', ''],
    'Search and social' => ['/admin/settings/seo', 'Save search and social settings', ''],
    'Addresses' => ['/admin/settings/addresses', 'Save address settings', ''],
    'AI' => ['/admin/settings/ai', 'Save AI settings', ''],
]);

it('draws each section of every other settings page as a panel under its built heading', function (string $path, string $first): void {
    $page = cmSignIn($this->admin)->navigate($path);

    cmWaitUntil($page, "document.querySelectorAll('[data-test=\"settings-panel\"]').length > 0")
        ->assertScript("document.querySelector('[data-test=\"settings-panel\"] h2')?.textContent.trim() ?? ''", $first)
        ->assertScript("[...document.querySelectorAll('[data-test=\"settings-panel\"]')].every((panel) => panel.querySelector('h2') === null || panel.querySelector('h2').textContent.trim() !== '')")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
})->with([
    'People' => ['/admin/settings/people', 'Sign-in rules'],
    'Search and social' => ['/admin/settings/seo', 'What search engines and feed readers fetch'],
    'AI' => ['/admin/settings/ai', 'AI services'],
    'Site code' => ['/admin/settings/site-code', 'Head'],
]);
