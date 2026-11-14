<?php

declare(strict_types=1);

use App\Domain\Settings\Modules;
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
    $this->actingAs($this->admin)->get(route('settings.discussion'))->assertOk();
});

it('passes axe on Settings › Discussion, every target 24 pixels or more, in the light and dark schemes (D17)', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/discussion')
        ->assertSee('Comments on this site')
        ->assertSee('Commenters’ details')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('switches comments off, leaving the switch alone on the page, and on again (E1)', function (): void {
    cmSignIn($this->admin)
        ->navigate('/admin/settings/discussion')
        ->select('#field-comments', 'off')
        ->click('#comments-switch button[type="submit"]')
        ->assertSee('Comments switched off.')
        ->assertSee('Comments are off, so the settings for them are hidden.')
        ->assertDontSee('Where readers can comment')
        ->select('#field-comments', 'on')
        ->click('#comments-switch button[type="submit"]')
        ->assertSee('Comments switched on.')
        ->assertSee('Where readers can comment');

    expect(Modules::on(Modules::COMMENTS))->toBeTrue();
});
