<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Content\ItemRef;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = cmItem($this->author, 'Harbour news');
});

it('sends CommentStatusChanged for a new comment, a moderation and an admin’s reply', function (): void {
    cmSettings(['hold_all' => true]);
    $heard = hkHear(CommentStatusChanged::class);

    $submitted = cmSubmit($this->item, 'Lovely piece.');
    $comment = Comment::query()->findOrFail($submitted->commentId);
    cmModerate($comment, 'approved', $this->editor);
    $reply = cmReply($comment->refresh(), $this->editor);

    expect($heard)->toHaveCount(3)
        ->and([$heard[0]->commentId, $heard[0]->from, $heard[0]->to, $heard[0]->actorId])->toBe([$submitted->commentId, null, 'pending', null])
        ->and($heard[0]->item->id)->toBe($this->item->id)
        ->and($heard[0]->item->status)->toBe('published')
        ->and([$heard[1]->commentId, $heard[1]->from, $heard[1]->to, $heard[1]->actorId])->toBe([$submitted->commentId, 'pending', 'approved', (string) $this->editor->id])
        ->and([$heard[2]->commentId, $heard[2]->from, $heard[2]->to, $heard[2]->actorId])->toBe([$reply->replyId, null, 'approved', (string) $this->editor->id]);
});

it('sends nothing when a moderation changes nothing', function (): void {
    $comment = cmComment($this->item, 'approved');
    $heard = hkHear(CommentStatusChanged::class);

    cmModerate($comment, 'approved', $this->editor);

    expect($heard->getArrayCopy())->toBe([]);
});

it('announces nothing when a moderation or a reply is refused', function (): void {
    $comment = cmComment($this->item, 'pending');
    $stranger = User::factory()->create()->assignRole('author');
    $heard = hkHear(CommentStatusChanged::class);

    expect(fn () => cmModerate($comment, 'approved', $stranger))->toThrow(AuthorizationException::class)
        ->and(fn () => cmReply($comment, $stranger))->toThrow(AuthorizationException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

it('carries no commenter’s name, email or address', function (): void {
    $names = array_map(
        fn (ReflectionProperty $property): string => strtolower($property->getName()),
        [...(new ReflectionClass(CommentStatusChanged::class))->getProperties(), ...(new ReflectionClass(ItemRef::class))->getProperties()],
    );

    foreach ($names as $name) {
        expect($name)->not->toContain('name')
            ->and($name)->not->toContain('email')
            ->and($name)->not->toContain('ip')
            ->and($name)->not->toContain('useragent')
            ->and($name)->not->toContain('author');
    }

    expect($names)->toContain('commentid', 'actorid');
});

it('tells the comment’s item in the site’s language (I-3360)', function (): void {
    cmSettings(['language' => 'pt-BR'], 'general');
    $heard = hkHear(CommentStatusChanged::class);

    cmSubmit($this->item, 'Lovely piece.');

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->item->language)->toBe('pt-BR');
});
