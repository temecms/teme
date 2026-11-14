<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->contributor = User::factory()->create(['name' => 'Chidi Contributor'])->assignRole('contributor');
    $this->discussionOf = fn (Content $item): string => (string) DB::table('contents')->where('id', $item->id)->value('comment_status');
});

it('sets Discussion on every item it\'s given, each through SetDiscussion, and says so (I-688)', function (): void {
    $one = cmItem($this->editor, 'One');
    $two = cmItem($this->author, 'Two');

    lscrBulkEdit($this->editor, [$one, $two], ['discussion' => 'closed'])
        ->assertOk()
        ->assertJsonPath('done', 2)
        ->assertJsonPath('changed', [
            ['id' => $one->id, 'title' => 'One', 'summary' => 'discussion set to Closed'],
            ['id' => $two->id, 'title' => 'Two', 'summary' => 'discussion set to Closed'],
        ])
        ->assertJsonPath('skipped', []);

    expect(($this->discussionOf)($one))->toBe('closed')
        ->and(($this->discussionOf)($two))->toBe('closed');
});

it('says when an item already has the Discussion asked for', function (): void {
    $one = cmItem($this->editor, 'One');

    lscrBulkEdit($this->editor, [$one], ['discussion' => 'open'])
        ->assertOk()
        ->assertJsonPath('changed.0.summary', 'discussion already Open');
});

it('sets Discussion after the status, in the same line', function (): void {
    $draft = lifecycleDraft($this->author, 'A draft');

    lscrBulkEdit($this->editor, [$draft], ['status' => 'published', 'discussion' => 'hidden'])
        ->assertOk()
        ->assertJsonPath('changed.0.summary', 'published; discussion set to Hidden');

    expect(DB::table('contents')->where('id', $draft->id)->value('status'))->toBe('published')
        ->and(($this->discussionOf)($draft))->toBe('hidden');
});

it('reports a Discussion it may not set beside what did change, and writes nothing to it: a contributor\'s own draft (I-571)', function (): void {
    $draft = lifecycleDraft($this->contributor, 'Notes');
    $tag = taxonomyTerm('tag', 'Harbour');

    lscrBulkEdit($this->contributor, [$draft], ['add_terms' => [$tag->id], 'discussion' => 'closed'])
        ->assertOk()
        ->assertJsonPath('done', 1)
        ->assertJsonPath('changed.0.summary', 'terms changed; discussion not changed: You may not change this item.');

    expect(($this->discussionOf)($draft))->toBe('open')
        ->and($draft->fresh()->workingRevision->terms)->toBe([$tag->id]);
});

it('skips an item whose Discussion alone was asked for and refused, writing nothing to it, and sets the rest (I-571)', function (): void {
    $mine = cmItem($this->author, 'Mine');
    $theirs = cmItem($this->editor, 'Theirs');

    lscrBulkEdit($this->author, [$mine, $theirs], ['discussion' => 'closed'])
        ->assertOk()
        ->assertJsonPath('done', 1)
        ->assertJsonPath('changed', [['id' => $mine->id, 'title' => 'Mine', 'summary' => 'discussion set to Closed']])
        ->assertJsonPath('skipped', [['id' => $theirs->id, 'title' => 'Theirs', 'reason' => 'You may not change this item.']]);

    expect(($this->discussionOf)($theirs))->toBe('open');
});

it('leaves Discussion alone unless asked, and refuses a state that isn\'t one', function (): void {
    $one = cmItem($this->editor, 'One');
    $tag = taxonomyTerm('tag', 'Harbour');

    lscrBulkEdit($this->editor, [$one], ['add_terms' => [$tag->id]])
        ->assertOk()
        ->assertJsonPath('changed.0.summary', 'terms changed');
    lscrBulkEdit($this->editor, [$one], ['discussion' => 'locked'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('discussion');

    expect(($this->discussionOf)($one))->toBe('open');
});

it('skips each item, saying why, while comments are off', function (): void {
    $one = cmItem($this->editor, 'One');
    cmSettings(['comments' => 'off'], 'modules');

    lscrBulkEdit($this->editor, [$one], ['discussion' => 'closed'])
        ->assertOk()
        ->assertJsonPath('done', 0)
        ->assertJsonPath('skipped.0.reason', 'Comments are switched off.');

    expect(($this->discussionOf)($one))->toBe('open');
});
