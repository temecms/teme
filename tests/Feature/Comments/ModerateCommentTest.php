<?php

declare(strict_types=1);

use App\Domain\Comments\ModeratedComment;
use App\Domain\Comments\Operations\Input\ModerateCommentInput;
use App\Domain\Comments\Operations\ModerateComment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->author);
});

it('approves a held comment, and moves its count (I-273)', function (): void {
    $comment = cmComment($this->item, 'pending');

    expect(cmModerate($comment, 'approved', $this->editor))->toBe('approved')
        ->and($comment->refresh()->status)->toBe('approved')
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 1, 'pending_count' => 0, 'last_comment_id' => $comment->id]);
});

it('says where the comment was and where it went, read under its lock, and who wrote it, for Undo (1.8c, D9)', function (): void {
    $comment = cmComment($this->item, 'pending', attributes: ['author_name' => 'Ada Guest']);
    $move = fn (): ModeratedComment => app(ModerateComment::class)->execute(new ModerateCommentInput($comment->id, 'spam', (string) $this->editor->id));

    $moved = $move();
    $again = $move();

    expect([$moved->commentId, $moved->before, $moved->after, $moved->authorName, $moved->changed()])->toBe([$comment->id, 'pending', 'spam', 'Ada Guest', true])
        ->and([$again->before, $again->after, $again->changed()])->toBe(['spam', 'spam', false])
        ->and($comment->refresh()->previous_status)->toBe('pending');
});

it('remembers where a comment was before the trash or spam, and puts it back there when restored (D10)', function (): void {
    $approved = cmComment($this->item);
    $held = cmComment($this->item, 'pending');

    cmModerate($approved, 'trash', $this->editor);
    cmModerate($held, 'spam', $this->editor);
    cmModerate($held, 'trash', $this->editor);

    expect($approved->refresh()->only(['status', 'previous_status']))->toBe(['status' => 'trash', 'previous_status' => 'approved'])
        ->and($held->refresh()->only(['status', 'previous_status']))->toBe(['status' => 'trash', 'previous_status' => 'pending'])
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 0, 'pending_count' => 0]);

    cmModerate($approved, ModerateComment::RESTORE, $this->editor);
    cmModerate($held, ModerateComment::RESTORE, $this->editor);

    expect($approved->refresh()->only(['status', 'previous_status']))->toBe(['status' => 'approved', 'previous_status' => null])
        ->and($held->refresh()->status)->toBe('pending')
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 1, 'pending_count' => 1]);
});

it('restores a comment that remembers nothing, such as an imported one, to pending', function (): void {
    $imported = cmComment($this->item, 'trash');

    expect(cmModerate($imported, ModerateComment::RESTORE, $this->editor))->toBe('pending');
});

it('lets an author moderate the comments on their own items, an editor\'s there too, and on nobody else\'s (I-483)', function (): void {
    $editorsComment = cmComment($this->item, attributes: ['author_user_id' => $this->editor->id, 'author_name' => $this->editor->name]);
    $elsewhere = cmComment(cmItem(User::factory()->create()->assignRole('author'), 'Other news'), 'pending');

    expect(cmModerate($editorsComment, 'spam', $this->author))->toBe('spam')
        ->and(fn () => cmModerate($elsewhere, 'approved', $this->author))->toThrow(AuthorizationException::class)
        ->and($elsewhere->refresh()->status)->toBe('pending');
});

it('writes nothing when someone who doesn\'t look after the item\'s comments tries (authorization)', function (?string $role): void {
    $comment = cmComment($this->item, 'pending');
    $actorId = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;

    expect(fn () => app(ModerateComment::class)->execute(new ModerateCommentInput($comment->id, 'approved', $actorId)))->toThrow(AuthorizationException::class)
        ->and($comment->refresh()->status)->toBe('pending')
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 0, 'pending_count' => 1]);
})->with([
    'a contributor' => ['contributor'],
    'no one' => [null],
]);

it('refuses to move a tombstone, to move a comment where no moderator may, or to restore one that isn\'t in the trash or spam', function (): void {
    $tombstone = cmComment($this->item, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    $approved = cmComment($this->item);

    expect(fn () => cmModerate($tombstone, 'approved', $this->editor))->toThrow(ValidationException::class)
        ->and(fn () => cmModerate($approved, 'removed', $this->editor))->toThrow(ValidationException::class)
        ->and(fn () => cmModerate($approved, ModerateComment::RESTORE, $this->editor))->toThrow(ValidationException::class)
        ->and($approved->refresh()->status)->toBe('approved')
        ->and($tombstone->refresh()->status)->toBe('removed');
});

it('changes nothing when the comment already has the status asked for', function (): void {
    $comment = cmComment($this->item);
    $written = $comment->updated_at;
    $this->travel(1)->minutes();

    expect(cmModerate($comment, 'approved', $this->editor))->toBe('approved')
        ->and($comment->refresh()->updated_at?->equalTo($written))->toBeTrue()
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 1]);
});
