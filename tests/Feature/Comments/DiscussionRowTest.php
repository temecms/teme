<?php

declare(strict_types=1);

use App\Domain\Comments\Queries\ItemDiscussion;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->contributor = User::factory()->create(['name' => 'Chidi Contributor'])->assignRole('contributor');
    $this->item = cmItem($this->author, 'Harbour news');
    $this->discussionOf = fn (Content $item): string => (string) DB::table('contents')->where('id', $item->id)->value('comment_status');
});

it('gives the editor the item\'s Discussion and the days after which new comments stop (I-687)', function (): void {
    cmSettings(['close_after_days' => 14]);

    $this->actingAs($this->author)->get(route('content.edit', ['content' => $this->item->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('discussion', ['value' => 'open', 'closeAfterDays' => 14])
            ->where('session.can.publish', true));
});

it('draws no Discussion row while comments are off, or for an item whose type takes none (I-484)', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $this->actingAs($this->author)->get(route('content.edit', ['content' => $this->item->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('discussion', null));

    cmSettings(['comments' => 'on'], 'modules');
    DB::table('contents')->where('id', $this->item->id)->update(['type' => 'region']);

    expect(app(ItemDiscussion::class)->of($this->item->id))->toBeNull();
});

it('saves the row at once, outside the revision, and answers the new state', function (): void {
    $revision = DB::table('contents')->where('id', $this->item->id)->value('working_revision_id');

    $this->actingAs($this->author)
        ->postJson(route('content.discussion', ['content' => $this->item->id]), ['discussion' => 'closed'])
        ->assertOk()
        ->assertExactJson(['discussion' => 'closed']);

    expect(($this->discussionOf)($this->item))->toBe('closed')
        ->and(DB::table('contents')->where('id', $this->item->id)->value('working_revision_id'))->toBe($revision);
});

it('refuses someone who may not publish the item, and writes nothing: a contributor on their own draft (I-571)', function (): void {
    $draft = lifecycleDraft($this->contributor, 'Notes');

    $this->actingAs($this->contributor)
        ->postJson(route('content.discussion', ['content' => $draft->id]), ['discussion' => 'hidden'])
        ->assertForbidden();

    expect(($this->discussionOf)($draft))->toBe('open');
});

it('says why it refused, keyed to the row, when comments were switched off meanwhile', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    $this->actingAs($this->author)
        ->postJson(route('content.discussion', ['content' => $this->item->id]), ['discussion' => 'closed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discussion' => 'Comments are switched off.']);

    expect(($this->discussionOf)($this->item))->toBe('open');
});
