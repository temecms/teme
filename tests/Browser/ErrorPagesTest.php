<?php

/**
 * The admin's 403 and the 503 page at desktop width: what each says, and
 * that each passes axe.
 *
 * @package Teme
 * @license MIT
 */

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    Route::get('/_unavailable', fn () => abort(503));

    cmSettings(['site_title' => 'The Garden City Ledger'], 'general');
    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
});

it('names the page, the permission and who holds it on the 403 at 1440, and passes axe', function (): void {
    $page = cmSignIn($this->author)->resize(1440, 900);
    cmNavigate($page, '/admin/settings/ai');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="error-403-in-admin"]\') !== null');

    $page->assertScript('document.querySelectorAll("h1").length === 1')
        ->assertScript('document.querySelector(\'[data-test="error-403-in-admin"]\').closest("main, body").innerText.includes("Settings is for administrators.")')
        ->assertScript('document.body.innerText.includes("It needs the Manage AI settings permission.")')
        ->assertScript('document.body.innerText.includes("You’re signed in as Ada Author, an author.")');

    expect(cmAxeViolations($page))->toBe([]);
});

it('draws the 503 page at 1440 with its heading, the Check now button, and passes axe', function (): void {
    $page = visit('/_unavailable')->resize(1440, 900);
    cmWaitUntil($page, 'document.querySelector("h1") !== null');

    $page->assertScript('document.querySelector("h1").innerText.trim() === "Down for maintenance"')
        ->assertScript('document.body.innerText.includes("Check now")')
        ->assertScript('document.body.innerText.includes("The Garden City Ledger")');

    // The page refreshes itself on purpose (it says so, and has a Check now button), so the meta-refresh rule is the one finding allowed.
    $found = array_values(array_filter(cmAxeViolations($page), fn (string $line): bool => ! str_starts_with($line, 'meta-refresh')));

    expect($found)->toBe([]);
});
