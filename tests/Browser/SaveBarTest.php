<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Backups\ReadyBackups;

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
    $ready = ReadyBackups::atFolder();

    try {
        $page = cmSignIn($this->admin)->navigate('/admin/tools/backups')->click('[data-test="backup-place-add"]');
        cmWaitUntil($page, 'document.querySelector("#password") !== null');
        $page->type('#password', 'password')->click('[data-test="confirm-password-button"]');
        cmWaitUntil($page, 'document.querySelector("#setup-name") !== null');

        $page->assertScript('document.querySelector(\'[data-test="save-bar"]\').hidden === true')
            ->type('#setup-name', 'Second place');
        cmWaitUntil($page, 'document.querySelector(\'[data-test="save-bar"]\').hidden === false');

        $page->click('[data-test="place-cancel"]');
        cmWaitUntil($page, 'document.querySelector(\'[data-test="leave-guard"]\') !== null');
        shellSettled($page, '[data-test="leave-guard"]');
        $page->click('[data-test="leave-guard-stay"]');
        cmWaitUntil($page, 'document.querySelector(\'[data-test="leave-guard"]\') === null && location.pathname === "/admin/tools/backups/places/new" && document.querySelector("#setup-name").value === "Second place"');

        $page->click('[data-test="save-bar-discard"]');
        cmWaitUntil($page, 'document.querySelector("#setup-name").value === "This server" && document.querySelector(\'[data-test="save-bar"]\').hidden === true');

        $page->type('#setup-name', 'Third place')->click('[data-test="place-cancel"]');
        cmWaitUntil($page, 'document.querySelector(\'[data-test="leave-guard"]\') !== null');
        shellSettled($page, '[data-test="leave-guard"]');
        $page->click('[data-test="leave-guard-leave"]');
        cmWaitUntil($page, 'location.pathname === "/admin/tools/backups"');
    } finally {
        $ready->delete();
    }
});
