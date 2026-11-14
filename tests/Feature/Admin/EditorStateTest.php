<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
    $this->open = fn (string $id): mixed => $this->actingAs($this->editor)->get("/admin/content/{$id}/edit");
});

it('says a live item has unpublished changes once a revision is saved after the published one, and never a draft', function (): void {
    $draft = lifecycleDraft($this->editor, 'Harbour news');
    docSave($draft, $this->editor, docWith(docText('Three days of music.')));
    $live = addrPublished($this->editor, 'Ferry fares');

    ($this->open)($draft->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('session.unpublishedChanges', false));
    ($this->open)($live->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('session.unpublishedChanges', false));

    docSave($live, $this->editor, docWith(docText('A correction to the fares.')));

    ($this->open)($live->id)->assertInertia(fn (Assert $page) => $page->where('session.unpublishedChanges', true));
});

it('counts the person\'s own autosave on a live item as unpublished changes, and not someone else\'s', function (): void {
    $live = addrPublished($this->editor, 'Ferry fares');
    $other = User::factory()->create()->assignRole('editor');
    edSave($live->id, $other, docWith(docText('Their half sentence')));

    ($this->open)($live->id)->assertInertia(fn (Assert $page) => $page->where('session.unpublishedChanges', false));

    edSave($live->id, $this->editor, docWith(docText('My half sentence')));

    ($this->open)($live->id)->assertInertia(fn (Assert $page) => $page->where('session.unpublishedChanges', true));
});

it('answers each publishing decision with whether unpublished changes remain', function (): void {
    $live = addrPublished($this->editor, 'Ferry fares');
    docSave($live, $this->editor, docWith(docText('A correction to the fares.')));
    $working = $live->refresh()->working_revision_id;

    $this->actingAs($this->editor)->postJson("/admin/content/{$live->id}/schedule", [
        'revision_id' => $working,
        'action' => 'update',
        'run_at' => now()->addDay()->toIso8601String(),
    ])->assertOk()->assertJsonPath('unpublishedChanges', true);

    $this->actingAs($this->editor)->postJson("/admin/content/{$live->id}/publish", ['revision_id' => $working])
        ->assertOk()
        ->assertJsonPath('unpublishedChanges', false);
});
