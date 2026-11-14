<?php

declare(strict_types=1);

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SiteVerification;
use App\Domain\Seo\WholeSite;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

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

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('settings.seo'))->assertOk();
});

it('passes axe on Home\'s warning and on Settings › Search and social, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    cmSettings([WholeSite::DISCOURAGE => true, SiteVerification::TAGS => [['name' => 'google-site-verification', 'value' => 'abc']]], SearchAndSocial::KEY);
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->assertSee('Search engines are asked to stay away from this site.');

    // Signing in arrives in the light scheme and turns dark after; the warning's
    // button fades its colours across, so axe waits for the fade to end.
    cmWaitUntil($page, <<<'JS'
        document.querySelector('[data-test="home-search-engines"] [data-slot="button"]').getAnimations().length === 0
        JS);

    // No test has held Home as a whole to axe yet; the warning is this part's, so axe reads it alone.
    $home = $page->script(<<<'JS'
        async () => {
            const run = await window.axe.run(document.querySelector('[data-test="home-search-engines"]'), {
                runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
                rules: { 'target-size': { enabled: true } },
            });

            return run.violations.map((violation) => violation.id);
        }
        JS);

    $page->navigate('/admin/settings/seo')
        ->assertSee('This copy isn’t the live site.')
        ->assertSee('Search engines are asked to stay away from the whole site.')
        ->assertPresent('[data-test="pairs-row"]')
        ->assertSee('What robots.txt says now')
        ->assertNoAccessibilityIssues(3);

    $screen = cmSmallTargets($page);

    expect($home)->toBe([])
        ->and($screen['small'])->toBe([])
        ->and($screen['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('fills each row\'s example as its template and the separator are typed, and shows the home row\'s second page with its tagline (I-904)', function (): void {
    cmSettings(['tagline' => 'Harbour stories'], 'general');
    cmItem(User::factory()->create(['name' => 'Jane Doe'])->assignRole('author'), 'Harbour news');

    cmSignIn($this->admin)
        ->navigate('/admin/settings/seo')
        ->assertSeeIn('#example-title_home', 'Shows as: My Teme site')
        ->assertSeeIn('[data-test="seo-row-page-two"]', 'Page 2 shows as: My Teme site')
        ->type('[name="values[title_separator]"]', '|')
        ->assertSeeIn('[data-test="seo-row-page-two"]', 'My Teme site | Page 2 | Harbour stories')
        ->type('[name="values[title_type_post]"]', '{title} {sep} {type} {sep} {nothing}')
        ->assertSeeIn('#example-title_type_post', 'Shows as: Harbour news | Post')
        ->clear('[name="values[title_type_post]"]')
        ->assertSeeIn('#example-title_type_post', 'Shows as: Harbour news | My Teme site');
});

it('adds a verification tag, refuses a name Teme writes itself, and saves one that is fine (I-288)', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/settings/seo')
        ->assertSee('No verification tags.')
        ->click('[data-test="pairs-add"]')
        ->type('[name="values[site_verification][0][name]"]', 'robots')
        ->type('[name="values[site_verification][0][value]"]', 'index')
        ->click('[data-test="settings-save"]')
        ->assertSee('Row 1: Teme writes the robots tag itself, so it can’t be a verification tag.')
        ->type('[name="values[site_verification][0][name]"]', 'google-site-verification')
        ->click('[data-test="settings-save"]')
        ->assertSee('Search and social settings saved.');

    expect(SiteVerification::tags())->toBe([['name' => 'google-site-verification', 'value' => 'index']]);
});

it('asks search engines to stay away, says so, warns on Home until it is undone, and the warning\'s link leads back to the switch (I-873)', function (): void {
    cmSignIn($this->admin)
        ->assertDontSee('Search engines are asked to stay away from this site.')
        ->navigate('/admin/settings/seo')
        ->click('#field-discourage_search_engines')
        ->click('[data-test="settings-save"]')
        ->assertSee('Search engines are asked to stay away from every page, and the sitemap is off.')
        ->assertSee('Switched off while search engines are discouraged')
        ->navigate('/admin')
        ->assertSee('Search engines are asked to stay away from this site.')
        ->click('Open Search and social')
        ->assertPathIs('/admin/settings/seo')
        ->click('#field-discourage_search_engines')
        ->click('[data-test="settings-save"]')
        ->assertSee('Search engines may list the site again, and the sitemap is back.')
        ->navigate('/admin')
        ->assertDontSee('Search engines are asked to stay away from this site.');

    expect(WholeSite::discouraged())->toBeFalse();
});
