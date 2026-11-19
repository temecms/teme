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
});

it('shows the save bar once a field changes, asks before leaving, keeps what was typed on Keep editing, and discards on Discard', function (): void {
    $page = cmNavigate(cmSignIn($this->admin), '/admin/account/profile');
    cmWaitUntil($page, 'document.querySelector(\'nav[aria-label="Account"]\') !== null && document.querySelector("#name") !== null');
    $leave = 'nav[aria-label="Account"] a[href$="/admin/account/preferences"]';

    $page->assertScript('document.querySelector(\'[data-test="save-bar"]\').hidden === true')
        ->clear('#name')
        ->type('#name', 'Second name');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === false');

    $page->click($leave);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="leave-guard"]\') !== null');
    shellSettled($page, '[data-test="leave-guard"]');
    $page->click('[data-test="leave-guard-stay"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="leave-guard"]\') === null && location.pathname === "/admin/account/profile" && document.querySelector("#name").value === "Second name"');

    $page->click('[data-test="save-bar-discard"]');
    cmWaitUntil($page, 'document.querySelector("#name").value === "Ada Admin" && document.querySelector(\'[data-test="save-bar"]\').hidden === true');

    $page->clear('#name')->type('#name', 'Third name')->click($leave);
    cmWaitUntil($page, 'document.querySelector(\'[data-test="leave-guard"]\') !== null');
    shellSettled($page, '[data-test="leave-guard"]');
    $page->click('[data-test="leave-guard-leave"]');
    cmWaitUntil($page, 'location.pathname === "/admin/account/preferences"');

    expect($this->admin->refresh()->name)->toBe('Ada Admin');
});
