<?php

declare(strict_types=1);

use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\Input\ReplyToCommentInput;
use App\Domain\Comments\Operations\ReplyToComment;
use App\Domain\Comments\Queries\CommentDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor', 'email' => 'Eze@Example.com'])->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
});

it('replies under the comment, approved at once, as the signed-in person, with no IP address, browser or key (C6, D11, I-647)', function (): void {
    $answered = cmNext($this->his, attributes: ['ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Test)']);

    $posted = cmReply($answered, $this->editor, "Thanks.\n\nSee https://tides.example");
    $reply = Comment::query()->findOrFail($posted->replyId);

    expect([$posted->approvedAnswered, $posted->answeredName])->toBe([false, 'Ada Guest'])
        ->and($reply->only(['content_id', 'parent_id', 'depth', 'status', 'author_name', 'author_email', 'author_url', 'author_user_id', 'ip', 'user_agent', 'commenter_token_hash']))->toBe([
            'content_id' => $this->his->id,
            'parent_id' => $answered->id,
            'depth' => 2,
            'status' => 'approved',
            'author_name' => 'Eze Editor',
            'author_email' => 'eze@example.com',
            'author_url' => null,
            'author_user_id' => $this->editor->id,
            'ip' => null,
            'user_agent' => null,
            'commenter_token_hash' => null,
        ])
        ->and($reply->path)->toBe($answered->path.$reply->id.'/')
        ->and($reply->body)->toEqual(CommentText::toDocument("Thanks.\n\nSee https://tides.example"))
        ->and($reply->moderation)->toEqual(['by' => 'Teme', 'status' => 'approved', 'reason' => 'Approved at once: whoever looks after this item’s comments.', 'signals' => ['rule' => 'looks-after', 'written' => 'admin']])
        ->and(cmChangedAt($reply)->equalTo(now()))->toBeTrue()
        ->and(cmStats($this->his))->toMatchArray(['approved_count' => 2]);
});

it('approves the comment it answers when that one waits, through ModerateComment (C6, D11)', function (): void {
    $answered = cmNext($this->hers, 'pending', attributes: ['author_name' => 'Bola Ade']);

    $posted = cmReply($answered, $this->author);

    expect([$posted->approvedAnswered, $posted->answeredName])->toBe([true, 'Bola Ade'])
        ->and($answered->refresh()->status)->toBe('approved')
        ->and(cmStats($this->hers))->toMatchArray(['approved_count' => 2, 'pending_count' => 0]);
});

it('puts a reply to a comment at the deepest level beside it, as the thread does (D12, I-272)', function (): void {
    cmSettings(['thread_depth' => 2]);
    $top = cmNext($this->his);
    $deep = cmNext($this->his, 'approved', $top);

    $reply = Comment::query()->findOrFail(cmReply($deep, $this->editor)->replyId);

    expect([$reply->parent_id, $reply->depth, $reply->path])->toBe([$top->id, 2, $top->path.$reply->id.'/']);
});

it('answers on a closed item, and never a ping, a tombstone, spam, the trash or a comment on an item in the trash (C8, D11)', function (): void {
    $onClosed = cmNext(cmItem($this->editor, 'Tide tables', 'page'));
    $ping = cmNext($this->his, attributes: ['kind' => 'pingback', 'author_name' => 'Harbour Weekly', 'author_email' => null, 'author_url' => 'https://weekly.example/post']);
    $tombstone = cmNext($this->his, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    $spam = cmNext($this->his, 'spam');
    $trash = cmNext($this->his, 'trash');
    $onTrashed = cmNext($this->hers);
    pageTrash($this->hers, $this->author);

    expect(cmReply($onClosed, $this->editor)->replyId)->toBeString()
        ->and(fn () => cmReply($ping, $this->editor))->toThrow(ValidationException::class, 'Pingbacks and trackbacks take no replies.')
        ->and(fn () => cmReply($tombstone, $this->editor))->toThrow(ValidationException::class, 'Only a comment that’s approved or waiting can be answered.')
        ->and(fn () => cmReply($spam, $this->editor))->toThrow(ValidationException::class, 'Only a comment that’s approved or waiting can be answered.')
        ->and(fn () => cmReply($trash, $this->editor))->toThrow(ValidationException::class, 'Only a comment that’s approved or waiting can be answered.')
        ->and(fn () => cmReply($onTrashed, $this->editor))->toThrow(ValidationException::class, 'Restore its post or page before replying to its comments.')
        ->and(Comment::query()->whereIn('parent_id', [$ping->id, $tombstone->id, $spam->id, $trash->id, $onTrashed->id])->exists())->toBeFalse();
});

it('refuses an empty or overlong reply in its own words, and writes nothing (D17, question 1)', function (string $text, string $words): void {
    $answered = cmNext($this->his, 'pending');

    expect(fn () => cmReply($answered, $this->editor, $text))
        ->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe(['text' => [$words]]))
        ->and($answered->refresh()->status)->toBe('pending')
        ->and(Comment::query()->where('parent_id', $answered->id)->exists())->toBeFalse();
})->with([
    'nothing' => [" \n ", 'Write your reply.'],
    'too much' => [str_repeat('a', 5001), 'Shorten your reply to 5,000 characters or fewer. It has 5,001.'],
]);

it('writes nothing for someone who doesn\'t look after the item\'s comments, nor for no one (authorization)', function (): void {
    $answered = cmNext($this->his, 'pending');

    expect(fn () => cmReply($answered, $this->author))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReplyToComment::class)->execute(new ReplyToCommentInput($answered->id, 'Hello.', null)))->toThrow(AuthorizationException::class)
        ->and($answered->refresh()->status)->toBe('pending')
        ->and(Comment::query()->where('parent_id', $answered->id)->exists())->toBeFalse();
});

it('tells the drawer a reply was written in the admin, so it says why there\'s no address (C6, I-647)', function (): void {
    $answered = cmNext($this->his, attributes: ['ip' => '203.0.113.7']);
    $reply = cmReply($answered, $this->editor)->replyId;
    $details = fn (string $id): array => app(CommentDetails::class)->find((int) $this->editor->id, $id)['details'] ?? [];

    expect($details($reply))->toMatchArray(['ip' => null, 'userAgent' => null, 'writtenInAdmin' => true, 'signedIn' => true])
        ->and($details($answered->id))->toMatchArray(['ip' => '203.0.113.7', 'writtenInAdmin' => false]);
});

it('posts a reply from the screen, says so, and answers to the list as it was (D13)', function (): void {
    $waiting = cmNext($this->hers, 'pending', attributes: ['author_name' => 'Bola Ade']);
    $shown = cmNext($this->hers);
    $post = fn (string $id, string $text = 'Thanks for this.') => $this->actingAs($this->author)
        ->from(route('comments.index', ['view' => 'all']))
        ->post(route('comments.reply'), ['id' => $id, 'text' => $text]);

    $post($shown->id)
        ->assertRedirect(route('comments.index', ['view' => 'all']))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Reply posted.');
    $post($waiting->id)->assertInertiaFlash('toast.message', 'Reply posted. The comment by Bola Ade is approved too.');
    $post($shown->id, '')->assertSessionHasErrors(['text' => 'Write your reply.']);
    $post(cmId())
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Nothing was posted, because the comment had changed since you opened the list.');
});
