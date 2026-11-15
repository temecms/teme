<?php

declare(strict_types=1);

use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\Models\BlockedHost;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\People\Models\Person;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;

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

    $this->admin = User::factory()->create(['name' => 'Ada Admin', 'slug' => 'ada'])->assignRole('administrator');

    // Waits for words on the page; the plugin's assertSee() looks once (issue #83).
    $this->textShown = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text, JSON_THROW_ON_ERROR).')');

    // A site with the fediverse on: the blog with a follower, an author, a
    // server not answering and a blocked one.
    $this->filled = function (): void {
        Inbox::switch(true);
        Inbox::followable(FediverseSettingsGroup::BOTH);
        $blog = Actors::blog();
        Actors::application();
        Person::query()->create(['user_id' => $this->admin->id]);
        Follower::query()->insert(['local_actor_id' => $blog->id, 'remote_actor_id' => Actors::remote()->id, 'since' => now()]);
        Actors::remote('https://quiet.example/users/bo', ['failing_since' => now()->subDays(3)]);
        BlockedHost::query()->create(['host' => 'spam.example', 'reason' => 'Spam and abuse', 'created_at' => now()]);
    };

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('settings.fediverse'))->assertOk();
});

it('passes axe on Settings › Fediverse at 1280 pixels, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    ($this->filled)();
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/settings/fediverse');
    ($this->textShown)($page, '@blog@');

    $page->assertSee('quiet.example')
        ->assertSee('spam.example')
        ->assertSee('Nothing imported yet.')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('fits on a phone at 375 pixels, nothing scrolling sideways, and passes axe there, in the light and dark schemes', function (string $scheme): void {
    ($this->filled)();
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)->resize(375, 812)->navigate('/admin/settings/fediverse');
    ($this->textShown)($page, '@blog@');

    $page->assertPresent('[data-test="fediverse-accounts-table"]')
        ->assertPresent('[data-test="fediverse-unreachable-table"]')
        ->assertPresent('[data-test="fediverse-blocked-table"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);

it('switches the fediverse on, blocks and unblocks a server, and turns an author on, each step saying so', function (): void {
    $page = cmSignIn($this->admin)->resize(1280, 900)->navigate('/admin/settings/fediverse');
    ($this->textShown)($page, 'No accounts yet.');

    $page->click('#field-fediverse');
    ($this->textShown)($page, 'The fediverse is on. People on other servers can follow the site.');
    ($this->textShown)($page, '@blog@');

    $page->type('#fediverse-block-host', 'spam.example')
        ->type('#fediverse-block-reason', 'Spam')
        ->click('[data-test="fediverse-block"]');
    ($this->textShown)($page, 'spam.example is blocked. Nothing from it is accepted, and nothing is sent to it.');

    $page->click('[data-test="fediverse-unblock"]');
    ($this->textShown)($page, 'spam.example is unblocked.');

    Inbox::followable(FediverseSettingsGroup::BOTH);
    Person::query()->create(['user_id' => $this->admin->id]);

    $page->navigate('/admin/settings/fediverse')->click('[data-test="fediverse-author-on"]');
    ($this->textShown)($page, 'Ada Admin can be followed as @ada@');

    expect(FediverseActor::query()->where('kind', FediverseActor::PERSON)->sole()->username)->toBe('ada')
        ->and(BlockedHost::query()->count())->toBe(0);
});

it('shows the switch locked to someone without settings.modules.manage, and their switching writes nothing', function (): void {
    $manager = User::factory()->create()->assignRole('editor')->givePermissionTo('settings.fediverse.manage');

    $page = cmSignIn($manager)->resize(1280, 900)->navigate('/admin/settings/fediverse');
    ($this->textShown)($page, 'Only administrators can switch the fediverse on or off.');

    $page->assertAttribute('#field-fediverse', 'disabled', '')
        ->assertAttribute('#field-fediverse', 'aria-checked', 'false');

    // Sent anyway, as a page opened before the lock would: refused, nothing written.
    $page->script(<<<'JS'
        window.__sent = null;
        fetch('/admin/settings/fediverse/switch', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'text/html', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
            body: JSON.stringify({ values: { fediverse: 'on' }, expected_version: 0 }),
        }).then((answer) => { window.__sent = answer.status; });
        JS);
    cmWaitUntil($page, 'window.__sent !== null');

    expect($page->script('window.__sent'))->toBe(403)
        ->and(DB::table('settings')->where('group_key', 'modules')->exists())->toBeFalse()
        ->and(FediverseActor::query()->count())->toBe(0);
});
