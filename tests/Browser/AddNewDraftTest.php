<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    // Warm both lists before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get('/admin/posts')->assertOk();
    $this->actingAs($this->editor)->get('/admin/pages')->assertOk();
});

it('opens a new empty draft in the editor with one press of Add new, the cursor in its title, and lists it as (no title)', function (string $list, string $words, string $type): void {
    $page = cmSignIn($this->editor)->navigate($list);
    cmWaitUntil($page, "document.querySelector('[data-test=\"add-new\"]')?.tagName === 'BUTTON' && document.querySelector('[data-test=\"add-new\"]').textContent.trim() === ".json_encode($words, JSON_THROW_ON_ERROR));

    $page->click('[data-test="add-new"]');
    cmWaitUntil($page, "location.pathname.endsWith('/edit')");
    cmEditorSettled($page);

    $draft = Content::query()->where('type', $type)->sole();

    cmWaitUntil($page, "location.pathname === '/admin/content/{$draft->id}/edit' && document.activeElement?.id === 'editor-title'");

    expect([$draft->title, $draft->status, $draft->slug])->toBe(['', 'draft', 'untitled']);

    // Back in the list, the empty draft is there by name.
    $page->navigate($list);
    cmWaitUntil($page, "[...document.querySelectorAll('a')].some((link) => link.getAttribute('href') === '/admin/content/{$draft->id}/edit' && link.textContent.trim() === '(no title)')");
})->with([
    'Posts' => ['/admin/posts', 'Add new post', 'post'],
    'Pages' => ['/admin/pages', 'Add new page', 'page'],
]);
