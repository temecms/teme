<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
    $this->moves = fn (User $actor, array $moves, bool $undo = false) => $this->actingAs($actor)
        ->from(route('comments.index'))
        ->post(route('comments.moderate'), ['moves' => $moves, 'undo' => $undo]);
    $this->delete = fn (User $actor, array $ids) => $this->actingAs($actor)
        ->from(route('comments.index', ['view' => 'spam']))
        ->post(route('comments.delete'), ['ids' => $ids]);
});

it('moves one comment, names its writer, and offers Undo back to where it was (D9, D11)', function (): void {
    $comment = cmNext($this->hers, 'pending', attributes: ['author_name' => 'Ada Guest']);

    ($this->moves)($this->author, [['id' => $comment->id, 'to' => 'approved']])
        ->assertRedirect(route('comments.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Approved: comment by Ada Guest.')
        ->assertInertiaFlash('toast.undo', ['href' => route('comments.moderate', [], false), 'data' => ['moves' => [['id' => $comment->id, 'to' => 'pending']], 'undo' => true]]);

    expect($comment->refresh()->status)->toBe('approved');
});

it('moves many, names the first kind, and Undo puts each back where it was and says so (D10, D11, question 1)', function (): void {
    $held = cmNext($this->hers, 'pending');
    $shown = cmNext($this->hers);
    $spam = cmNext($this->hers, 'spam');
    $back = [['id' => $held->id, 'to' => 'pending'], ['id' => $shown->id, 'to' => 'approved'], ['id' => $spam->id, 'to' => 'spam']];

    ($this->moves)($this->editor, [['id' => $held->id, 'to' => 'trash'], ['id' => $shown->id, 'to' => 'trash'], ['id' => $spam->id, 'to' => 'trash']])
        ->assertInertiaFlash('toast.message', '3 comments moved to the trash.')
        ->assertInertiaFlash('toast.undo.data.moves', $back);

    expect([$held->refresh()->status, $shown->refresh()->status, $spam->refresh()->status])->toBe(['trash', 'trash', 'trash']);

    ($this->moves)($this->editor, $back, undo: true)
        ->assertInertiaFlash('toast.message', 'Undone.')
        ->assertInertiaFlashMissing('toast.undo');

    expect([$held->refresh()->status, $shown->refresh()->status, $spam->refresh()->status])->toBe(['pending', 'approved', 'spam'])
        ->and(cmStats($this->hers))->toMatchArray(['approved_count' => 1, 'pending_count' => 1]);
});

it('says Not spam and Restored for a comment back from spam or the trash (D11)', function (): void {
    $spam = cmNext($this->hers, 'spam', attributes: ['author_name' => 'Bola Ade']);
    $trash = cmNext($this->hers, 'trash');
    $alsoTrash = cmNext($this->hers, 'trash');

    ($this->moves)($this->author, [['id' => $spam->id, 'to' => 'restore']])->assertInertiaFlash('toast.message', 'Not spam: comment by Bola Ade.');
    ($this->moves)($this->author, [['id' => $trash->id, 'to' => 'restore'], ['id' => $alsoTrash->id, 'to' => 'restore']])->assertInertiaFlash('toast.message', '2 comments restored.');
});

it('moves what it can, and says how many it couldn\'t because they had changed (question 2, D10)', function (): void {
    $kept = cmNext($this->hers, 'pending');
    $gone = cmNext($this->hers, 'spam');
    $tombstone = cmNext($this->hers, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    cmDelete($gone, $this->author);

    ($this->moves)($this->author, [['id' => $kept->id, 'to' => 'approved'], ['id' => $gone->id, 'to' => 'approved'], ['id' => $tombstone->id, 'to' => 'approved']])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', '1 comment approved. 2 weren’t, because they had changed since you opened the list.')
        ->assertInertiaFlash('toast.undo.data.moves', [['id' => $kept->id, 'to' => 'pending']]);

    expect($kept->refresh()->status)->toBe('approved');
});

it('writes nothing for a comment on an item the person doesn\'t look after, nor for someone who looks after none (authorization)', function (): void {
    $theirs = cmNext($this->his, 'pending');

    ($this->moves)($this->author, [['id' => $theirs->id, 'to' => 'approved']])
        ->assertInertiaFlash('toast.message', 'Nothing was moved, because the comment had changed since you opened the list.');
    ($this->moves)(User::factory()->create()->assignRole('contributor'), [['id' => $theirs->id, 'to' => 'approved']])->assertForbidden();

    expect($theirs->refresh()->status)->toBe('pending')
        ->and(cmStats($this->his))->toMatchArray(['approved_count' => 0, 'pending_count' => 1]);
});

it('refuses what isn\'t a list of up to 100 moves to a status a moderator may set (D10)', function (Closure $moves, string $field): void {
    ($this->moves)($this->editor, $moves())->assertSessionHasErrors($field);
})->with([
    'no moves' => [fn (): array => [], 'moves'],
    'more than 100' => [fn (): array => array_map(fn (int $n): array => ['id' => str_pad((string) $n, 26, '0', STR_PAD_LEFT), 'to' => 'approved'], range(1, 101)), 'moves'],
    'a status no moderator sets' => [fn (): array => [['id' => cmId(), 'to' => 'removed']], 'moves.0.to'],
    'not a comment\'s ID' => [fn (): array => [['id' => 'nope', 'to' => 'approved']], 'moves.0.id'],
]);

it('deletes many permanently, deepest first, so a comment whose replies all go goes too, and counts the tombstones left (D12)', function (): void {
    $top = cmNext($this->hers, 'spam');
    $reply = cmNext($this->hers, 'spam', $top);
    $kept = cmNext($this->hers, 'trash');
    cmNext($this->hers, 'approved', $kept);

    ($this->delete)($this->author, [$top->id, $kept->id, $reply->id])
        ->assertRedirect(route('comments.index', ['view' => 'spam']))
        ->assertInertiaFlash('toast.message', '3 comments deleted permanently. 1 stays as “This comment was removed”, so its replies keep their place.')
        ->assertInertiaFlashMissing('toast.undo');

    expect(Comment::query()->whereKey([$top->id, $reply->id])->exists())->toBeFalse()
        ->and($kept->refresh()->status)->toBe('removed');
});

it('deletes one, naming its writer, or says it left a tombstone (D12)', function (): void {
    $alone = cmNext($this->hers, 'trash', attributes: ['author_name' => 'Bola Ade']);
    $answered = cmNext($this->hers, 'spam');
    cmNext($this->hers, 'approved', $answered);

    ($this->delete)($this->author, [$alone->id])->assertInertiaFlash('toast.message', 'Deleted permanently: the comment by Bola Ade.');
    ($this->delete)($this->author, [$answered->id])->assertInertiaFlash('toast.message', 'Comment removed. Its replies keep their place under “This comment was removed”.');
});

it('writes nothing when deleting a comment the person doesn\'t look after, or one not in the trash or spam (authorization)', function (): void {
    $theirs = cmNext($this->his, 'spam');
    $shown = cmNext($this->hers);

    ($this->delete)($this->author, [$theirs->id, $shown->id])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Nothing was deleted, because the comments had changed since you opened the list.');

    expect([$theirs->refresh()->status, $shown->refresh()->status])->toBe(['spam', 'approved']);
});

it('answers to the list as it was, never the drawer, nor anything else the request came with (D23)', function (): void {
    $comment = cmNext($this->hers, 'pending');

    $this->actingAs($this->author)
        ->from('https://elsewhere.example/admin/comments?view=spam&item=abc&comment='.$comment->id.'&next=https://evil.example')
        ->post(route('comments.moderate'), ['moves' => [['id' => $comment->id, 'to' => 'spam']]])
        ->assertRedirect(route('comments.index', ['view' => 'spam', 'item' => 'abc']));
});
