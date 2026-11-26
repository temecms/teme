<?php

/**
 * The editor's Address popover: headed Address, copy, no Change address,
 * and a pending change that joins unsaved work until Save draft or Update.
 *
 * @package Teme
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

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

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->draft = lifecycleDraft($this->editor, 'Harbour news');
    $this->edit = fn (Content $item): string => "/admin/content/{$item->id}/edit";

    $this->actingAs($this->editor)->get(($this->edit)($this->draft))->assertOk();
});

it('opens the Address popover headed with close and copy, and keeps a typed address unsaved until Save draft', function (): void {
    $page = cmEditorSettled(
        cmNavigate(cmSignIn($this->editor)->resize(1440, 900), ($this->edit)($this->draft)),
    );

    $page->click('[data-test="editor-address"]');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="address-popover"]\') !== null');

    $page->assertSee('Address')
        ->assertSee("The last part of this post's address.")
        ->assertPresent('[data-test="address-close"]')
        ->assertPresent('[data-test="address-copy"]')
        ->assertDontSee('Change address')
        ->assertNoAccessibilityIssues(3);

    $page->type('[data-test="editor-slug"]', 'harbour-2026')
        ->keys('[data-test="editor-slug"]', 'Enter');

    cmWaitUntil($page, 'document.querySelector(\'[data-test="editor-address"]\')?.innerText.includes(\'harbour-2026\') === true');

    expect($this->draft->refresh()->slug)->toBe('harbour-news');

    $page->click('[data-test="editor-save"]');

    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && $this->draft->refresh()->slug !== 'harbour-2026') {
        usleep(200_000);
    }

    expect($this->draft->refresh()->slug)->toBe('harbour-2026');
});
