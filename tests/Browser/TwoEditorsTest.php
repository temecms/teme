<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Both browsers are served by one application, inside the test's process,
    // which keeps the signed-in user, the session's data and queued cookies
    // from one request to the next. Each request starts afresh, as on a real
    // server; without this, every request acts as whoever signed in last.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    // Presence takes two autosaves, each two seconds after typing stops.
    pest()->browser()->timeout(15_000);

    $this->ada = User::factory()->create(['name' => 'Ada Obi'])->assignRole('editor');
    $this->bea = User::factory()->create(['name' => 'Bea Eze'])->assignRole('editor');
});

afterEach(function (): void {
    pest()->browser()->timeout(5_000);
});

it('shows two editors each other, and loses neither text when one saves over the other (I-439)', function (): void {
    $article = searchPublished($this->ada, 'Harbour notes', 'The ferry runs at dawn.');
    $edit = '/admin/content/'.$article->id.'/edit';

    // Each visit opens its own browser context: two people, two sessions.
    $open = fn (User $person) => cmWaitUntil(
        visit('/admin/login')
            ->type('email', $person->email)
            ->type('password', 'password')
            ->click('[data-test="login-button"]')
            ->assertPathIs('/admin')
            ->navigate($edit),
        "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'",
    );

    $ada = $open($this->ada);
    $bea = $open($this->bea);

    // Presence comes from an autosave: Bea's is in, then Ada's own names her.
    $bea->type('.teme-prose', 'Bea writes about the tide.')->assertSee('Saved');
    $ada->type('.teme-prose', 'Ada writes about the ferry.')->assertSee('Bea Eze is also editing.');

    // Bea saves a checkpoint; Ada's next autosave comes back stale.
    $bea->keys('.teme-prose', 'Control+s');
    $ada->type('.teme-prose', 'Ada writes about the ferry, and the tide.')
        ->assertSee('Someone else changed this post')
        // The button, not the same words in bold in the dialog's explanation.
        ->click('button:has-text("Save mine as a new draft")')
        ->assertPathIsNot($edit);

    $working = fn (string $contentId): string => (string) DB::table('content_revisions')
        ->where('id', Content::query()->whereKey($contentId)->value('working_revision_id'))
        ->value('plain_text');
    $newDraft = Content::query()->whereKeyNot($article->id)->where('status', 'draft')->sole();

    // Neither text is lost: Bea's is the article's working revision, Ada's the new draft.
    expect($working($article->id))->toContain('Bea writes about the tide.')
        ->and($working($newDraft->id))->toContain('Ada writes about the ferry, and the tide.');
});
