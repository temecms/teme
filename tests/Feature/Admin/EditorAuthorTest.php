<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->item = lifecycleDraft($this->author, 'Harbour news');

    // The Author row's request, as the editor sends it.
    $this->change = fn (User $person, int $authorId, ?string $base = null): mixed => $this->actingAs($person)->postJson(
        "/admin/content/{$this->item->id}/author",
        ['author_id' => $authorId, 'expected_revision_id' => $base ?? $this->item->refresh()->working_revision_id],
    );
});

it('gives someone who may edit anyone\'s work the people an item can be credited to, and an author none', function (): void {
    $this->actingAs($this->editor)->get("/admin/content/{$this->item->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/edit')
            ->where('authors', [
                ['value' => $this->author->id, 'label' => 'Ada Author'],
                ['value' => $this->editor->id, 'label' => 'Eve Editor'],
            ]));

    $this->actingAs($this->author)->get("/admin/content/{$this->item->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('authors', null));
});

it('changes the author from the editor at once, writing no revision, and answers with the new name', function (): void {
    $revisions = DB::table('content_revisions')->where('content_id', $this->item->id)->count();

    ($this->change)($this->editor, $this->editor->id)
        ->assertOk()
        ->assertJsonPath('author', ['id' => $this->editor->id, 'name' => 'Eve Editor'])
        ->assertJsonPath('addressPattern', '/{slug}/')
        ->assertJsonPath('url', null);

    expect((int) $this->item->refresh()->author_id)->toBe($this->editor->id)
        ->and(DB::table('content_revisions')->where('content_id', $this->item->id)->count())->toBe($revisions);
});

it('writes nothing when someone who may not change who wrote it asks', function (string $who): void {
    $person = $who === 'the author' ? $this->author : User::factory()->create()->assignRole('contributor');

    ($this->change)($person, $this->editor->id)->assertForbidden();

    expect((int) $this->item->refresh()->author_id)->toBe($this->author->id);
})->with(['the author', 'a contributor']);

it('answers a stale base with 409, and changes nothing', function (): void {
    $base = $this->item->refresh()->working_revision_id;
    docSave($this->item, $this->author, docWith(docText('Saved in another window.')));

    ($this->change)($this->editor, $this->editor->id, $base)
        ->assertStatus(409)
        ->assertJsonPath('stale', true);

    expect((int) $this->item->refresh()->author_id)->toBe($this->author->id);
});

it('refuses an author who isn\'t anyone here, and changes nothing', function (): void {
    ($this->change)($this->editor, 999999)->assertUnprocessable();

    expect((int) $this->item->refresh()->author_id)->toBe($this->author->id);
});

it('refuses someone the Author select doesn\'t offer, such as a suspended account, and changes nothing', function (): void {
    $suspended = User::factory()->suspended()->create(['name' => 'Sam Suspended'])->assignRole('author');

    ($this->change)($this->editor, $suspended->id)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Choose someone who may write on this site.');

    expect((int) $this->item->refresh()->author_id)->toBe($this->author->id);
});

it('serves the author endpoint inside the web group, where CSRF is checked', function (): void {
    expect(Route::getRoutes()->getByName('content.author')->gatherMiddleware())->toContain('web');
});
