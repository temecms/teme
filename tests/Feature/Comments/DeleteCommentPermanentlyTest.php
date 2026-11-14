<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\DeleteCommentPermanently;
use App\Domain\Comments\Operations\Input\CommentActionInput;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->author);
});

it('deletes a comment in the trash or spam that has no replies', function (): void {
    $trashed = cmComment($this->item, 'trash');
    $spam = cmComment($this->item, 'spam');

    expect(cmDelete($trashed, $this->editor))->toBe('deleted')
        ->and(cmDelete($spam, $this->author))->toBe('deleted')
        ->and(Comment::query()->count())->toBe(0);
});

it('leaves a tombstone where a comment with replies was: its place kept, its words and its writer\'s details erased (I-278)', function (): void {
    $top = cmComment($this->item, 'trash', attributes: [
        'author_user_id' => $this->author->id, 'author_url' => 'https://ada.example', 'ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Test)',
        'commenter_token_hash' => str_repeat('a', 64), 'legacy_id' => 42, 'previous_status' => 'approved',
        'moderation' => ['by' => 'Teme', 'status' => 'pending', 'reason' => 'Held.', 'signals' => ['rule' => 'first-comment']],
    ]);
    $reply = cmComment($this->item, 'approved', $top);

    expect(cmDelete($top, $this->editor))->toBe('removed')
        ->and((array) DB::table('comments')->where('id', $top->id)->first())->toMatchArray([
            'status' => 'removed', 'path' => $top->path, 'depth' => 1, 'legacy_id' => 42,
            'author_name' => null, 'author_email' => null, 'author_url' => null, 'author_user_id' => null,
            'body' => null, 'commenter_token_hash' => null, 'ip' => null, 'user_agent' => null, 'moderation' => null, 'previous_status' => null,
        ])
        ->and($reply->refresh()->status)->toBe('approved');
});

it('keeps a reply still waiting under a tombstone, so once approved it never reads as an answer to something else (D3)', function (): void {
    $top = cmComment($this->item, 'spam');
    $waiting = cmComment($this->item, 'pending', $top);

    expect(cmDelete($top, $this->editor))->toBe('removed')
        ->and($waiting->refresh()->parent_id)->toBe($top->id);
});

it('deletes each tombstone left with nothing beneath it, up the thread, and keeps one that still holds a reply (D3)', function (): void {
    $top = cmComment($this->item, 'trash');
    $kept = cmComment($this->item, 'approved', $top);
    $middle = cmComment($this->item, 'trash', $top);
    $leaf = cmComment($this->item, 'trash', $middle);

    cmDelete($top, $this->editor);
    cmDelete($middle, $this->editor);

    expect(cmDelete($leaf, $this->editor))->toBe('deleted')
        ->and(Comment::query()->pluck('id')->all())->toEqualCanonicalizing([$top->id, $kept->id])
        ->and($top->refresh()->status)->toBe('removed');
});

it('deletes only from the trash or spam', function (string $status): void {
    $comment = cmComment($this->item, $status);

    expect(fn () => cmDelete($comment, $this->editor))->toThrow(ValidationException::class)
        ->and(Comment::query()->whereKey($comment->id)->exists())->toBeTrue();
})->with(['pending', 'approved']);

it('writes nothing when someone who doesn\'t look after the item\'s comments tries (authorization)', function (): void {
    $comment = cmComment($this->item, 'trash');

    expect(fn () => cmDelete($comment, User::factory()->create()->assignRole('contributor')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteCommentPermanently::class)->execute(new CommentActionInput($comment->id, null)))->toThrow(AuthorizationException::class)
        ->and(Comment::query()->whereKey($comment->id)->exists())->toBeTrue();
});

it('lets the operator delete from the trash with no one named, and refuses a null actor anywhere else (operations.md, D8)', function (): void {
    $comment = cmComment($this->item, 'trash');
    $asNobody = fn (): string => app(DeleteCommentPermanently::class)->execute(new CommentActionInput($comment->id, null));

    $this->app->instance(Elevation::class, new DeniedElevation);

    expect($asNobody)->toThrow(AuthorizationException::class)
        ->and(Comment::query()->whereKey($comment->id)->exists())->toBeTrue();

    $this->app->instance(Elevation::class, new OperatorElevation);

    expect($asNobody())->toBe('deleted')
        ->and(Comment::query()->whereKey($comment->id)->exists())->toBeFalse();
});

it('deletes only a comment whose status hasn\'t changed since the cut-off, read under its lock (C4, D3)', function (): void {
    $comment = cmComment($this->item, 'spam');
    $changed = cmChangedAt($comment);
    $delete = fn (CarbonImmutable $cutoff): string => app(DeleteCommentPermanently::class)->execute(new CommentActionInput($comment->id, (string) $this->editor->id, $cutoff));

    expect(fn () => $delete($changed->subMicrosecond()))->toThrow(ValidationException::class, 'The comment has changed since you opened the list.')
        ->and(Comment::query()->whereKey($comment->id)->exists())->toBeTrue()
        ->and($delete($changed))->toBe('deleted');
});
