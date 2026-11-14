<?php

declare(strict_types=1);

use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\CommentThrottle;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Comments\CommentCandidate;
use Teme\Contracts\Comments\CommentModerator;
use Teme\Contracts\Comments\ModerationVerdict;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com'])->assignRole('author');
    $this->item = cmItem($this->author);
});

it('holds a guest\'s first comment, stored as a restricted document in its place in its thread (I-267, I-272, I-275)', function (): void {
    $submitted = cmSubmit($this->item, "Lovely piece.\nSee https://example.org", ['email' => ' Ada@Example.com ', 'url' => 'https://ada.example']);
    $comment = Comment::query()->findOrFail($submitted->commentId);

    expect($submitted->status)->toBe('pending')
        ->and($submitted->published())->toBeFalse()
        ->and($comment->only(['content_id', 'parent_id', 'path', 'depth', 'status', 'author_name', 'author_email', 'author_url', 'author_user_id', 'ip', 'user_agent']))->toBe([
            'content_id' => $this->item->id,
            'parent_id' => null,
            'path' => $comment->id.'/',
            'depth' => 1,
            'status' => 'pending',
            'author_name' => 'Ada Guest',
            'author_email' => 'ada@example.com',
            'author_url' => 'https://ada.example',
            'author_user_id' => null,
            'ip' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Test)',
        ])
        ->and($comment->kind)->toBe(CommentKind::Comment)
        ->and($comment->body)->toEqual(CommentText::toDocument("Lovely piece.\nSee https://example.org"))
        ->and($comment->commenter_token_hash)->toBe(hash('sha256', 'browser-one-0123456789abcdef'))
        ->and(DB::table('comments')->where('commenter_token_hash', 'browser-one-0123456789abcdef')->exists())->toBeFalse()
        ->and($comment->moderation)->toEqual(['by' => 'Teme', 'status' => 'pending', 'reason' => 'Held: a first comment, or the first from this browser.', 'signals' => ['rule' => 'first-comment']])
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 0, 'pending_count' => 1]);
});

it('approves a returning guest: an approved comment with the same email address, sent from the same browser (I-275)', function (): void {
    cmComment($this->item, 'approved', attributes: ['commenter_token_hash' => hash('sha256', 'browser-one-0123456789abcdef')]);

    $submitted = cmSubmit($this->item, 'Back again.', ['email' => 'ADA@example.com']);

    expect($submitted->status)->toBe('approved')
        ->and($submitted->published())->toBeTrue()
        ->and(Comment::query()->findOrFail($submitted->commentId)->moderation['signals'] ?? null)->toEqual(['rule' => 'returning'])
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 2, 'pending_count' => 0]);
});

it('holds a returning email address from a new browser once, and trusts that browser from then on', function (): void {
    cmComment($this->item, 'approved', attributes: ['commenter_token_hash' => hash('sha256', 'browser-one-0123456789abcdef')]);
    $phone = ['commenterToken' => 'browser-two-0123456789abcdef'];

    $held = cmSubmit($this->item, 'From my phone.', $phone);
    Comment::query()->whereKey($held->commentId)->update(['status' => 'approved']);

    expect($held->status)->toBe('pending')
        ->and(cmSubmit($this->item, 'From my phone again.', $phone)->status)->toBe('approved');
});

it('approves at once whoever looks after the item\'s comments, past every list and check, named as their account names them (I-483, I-570)', function (): void {
    cmSettings(['block_list' => 'tide', 'hold_all' => true]);
    $editor = User::factory()->create(['name' => 'Eve Editor', 'email' => 'Eve@Example.com'])->assignRole('editor');

    foreach ([$editor, $this->author] as $person) {
        $submitted = cmSubmit($this->item, 'The tide tables are updated.', ['actorId' => (string) $person->id, 'name' => 'Someone Else', 'email' => 'else@example.com', 'commenterToken' => null]);
        $comment = Comment::query()->findOrFail($submitted->commentId);

        expect($submitted->status)->toBe('approved')
            ->and($comment->only(['author_name', 'author_email', 'author_user_id']))->toBe(['author_name' => $person->name, 'author_email' => strtolower($person->email), 'author_user_id' => $person->id])
            ->and($comment->moderation)->toEqual(['by' => 'Teme', 'status' => 'approved', 'reason' => 'Approved at once: whoever looks after this item’s comments.', 'signals' => ['rule' => 'looks-after']]);
    }
});

it('holds a contributor\'s first comment as a reader\'s, even on their own item, then knows them by their account (I-483, §3.4)', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $theirs = lifecycleDraft($contributor, 'Low tide');
    listsPublish($theirs, User::factory()->create()->assignRole('editor'));
    $signedIn = ['actorId' => (string) $contributor->id, 'commenterToken' => null];

    $first = cmSubmit($theirs->refresh(), 'A note on my own piece.', $signedIn);
    Comment::query()->whereKey($first->commentId)->update(['status' => 'approved']);

    expect($first->status)->toBe('pending')
        ->and(cmSubmit($theirs, 'And another.', $signedIn)->status)->toBe('approved');
});

it('places a reply under the comment it answers, one level deeper (I-272)', function (): void {
    $top = cmComment($this->item);

    $reply = Comment::query()->findOrFail(cmSubmit($this->item, 'I agree.', ['parentId' => $top->id])->commentId);

    expect($reply->only(['parent_id', 'path', 'depth']))->toBe(['parent_id' => $top->id, 'path' => "{$top->id}/{$reply->id}/", 'depth' => 2]);
});

it('places a reply to a comment at the deepest level beside it, and one to a deeper imported comment at the deepest level (I-272)', function (): void {
    cmSettings(['thread_depth' => 2]);
    $top = cmComment($this->item);
    $second = cmComment($this->item, parent: $top);
    $imported = cmComment($this->item, parent: $second);

    $beside = Comment::query()->findOrFail(cmSubmit($this->item, 'Beside it.', ['parentId' => $second->id])->commentId);
    $capped = Comment::query()->findOrFail(cmSubmit($this->item, 'At the deepest level.', ['parentId' => $imported->id])->commentId);

    expect($beside->only(['parent_id', 'depth']))->toBe(['parent_id' => $top->id, 'depth' => 2])
        ->and($capped->only(['parent_id', 'depth']))->toBe(['parent_id' => $top->id, 'depth' => 2]);
});

it('refuses a reply to a comment on another item, or to one that isn\'t approved, and writes nothing', function (): void {
    $elsewhere = cmComment(cmItem($this->author, 'Other news'));
    $waiting = cmComment($this->item, 'pending');

    foreach ([$elsewhere, $waiting] as $parent) {
        expect(fn () => cmSubmit($this->item, 'A reply.', ['parentId' => $parent->id]))->toThrow(ValidationException::class);
    }

    expect(Comment::query()->count())->toBe(2);
});

it('refuses comments while comments are switched off (I-484)', function (): void {
    cmSettings(['comments' => 'off'], 'modules');

    expect(fn () => cmSubmit($this->item))->toThrow(ValidationException::class, 'switched off')
        ->and(Comment::query()->count())->toBe(0);
});

it('refuses an item that takes no comments now, and writes nothing (I-274)', function (Closure $close, string $message): void {
    $close($this->item);

    expect(fn () => cmSubmit($this->item->refresh()))->toThrow(ValidationException::class, $message)
        ->and(Comment::query()->count())->toBe(0);
})->with([
    'closed' => [fn (Content $item) => DB::table('contents')->where('id', $item->id)->update(['comment_status' => 'closed']), 'closed'],
    'past the days the settings allow' => [function (Content $item): void {
        cmSettings(['close_after_days' => 30]);
        test()->travel(31)->days();
    }, 'closed'],
    'dated ahead' => [fn (Content $item) => DB::table('contents')->where('id', $item->id)->update(['published_at' => now()->addDay()]), 'takes no comments'],
    'in the trash' => [fn (Content $item) => pageTrash($item, $item->author), 'takes no comments'],
    'of a type that takes none' => [fn (Content $item) => DB::table('contents')->where('id', $item->id)->update(['type' => 'region']), 'takes no comments'],
]);

it('writes nothing when a site taking comments from signed-in people only refuses a guest (authorization)', function (): void {
    cmSettings(['signed_in_only' => true]);

    expect(fn () => cmSubmit($this->item))->toThrow(AuthorizationException::class)
        ->and(Comment::query()->count())->toBe(0)
        ->and(cmStats($this->item))->toBeNull()
        ->and(RateLimiter::attempts('comments:ip:203.0.113.7'))->toBe(0);
});

it('writes nothing for a suspended account (authorization)', function (): void {
    $suspended = User::factory()->create(['suspended_at' => now()])->assignRole('editor');

    expect(fn () => cmSubmit($this->item, 'Hello.', ['actorId' => (string) $suspended->id]))->toThrow(AuthorizationException::class)
        ->and(Comment::query()->count())->toBe(0);
});

it('refuses what a guest gives beyond its limits (study S §3.4)', function (array $overrides, string $field): void {
    expect(fn () => cmSubmit($this->item, overrides: $overrides))->toThrow(function (ValidationException $refused) use ($field): void {
        expect($refused->errors())->toHaveKey($field);
    });
})->with([
    'a name over 100 characters' => [['name' => str_repeat('a', 101)], 'name'],
    'no email address' => [['email' => null], 'email'],
    'a website that isn\'t on the web' => [['url' => 'javascript:alert(1)'], 'url'],
    'a comment over 5,000 characters' => [['text' => str_repeat('a', 5001)], 'text'],
]);

it('refuses a guest the name or email address of someone with an account here (Drupal)', function (): void {
    expect(fn () => cmSubmit($this->item, overrides: ['email' => 'JANE@example.com']))->toThrow(function (ValidationException $refused): void {
        expect($refused->errors())->toHaveKey('email');
    })->and(fn () => cmSubmit($this->item, overrides: ['name' => 'jane doe', 'email' => 'someone@example.com']))->toThrow(function (ValidationException $refused): void {
        expect($refused->errors())->toHaveKey('name');
    });
});

it('refuses the same words twice on one item from one email address, and takes them on another (WordPress)', function (): void {
    cmSubmit($this->item, 'Lovely piece.');

    expect(fn () => cmSubmit($this->item, 'Lovely piece.', ['commenterToken' => 'browser-two-0123456789abcdef']))->toThrow(ValidationException::class, 'already said')
        ->and(cmSubmit(cmItem($this->author, 'Other news'), 'Lovely piece.')->status)->toBe('pending');
});

it('refuses a sixth comment in ten minutes from one IP address, or from one email address (study S §3.4)', function (): void {
    foreach (range(1, 5) as $n) {
        cmSubmit($this->item, "Comment {$n}", ['email' => "reader{$n}@example.com"]);
        cmSubmit($this->item, "Elsewhere {$n}", ['ip' => "198.51.100.{$n}", 'email' => 'traveller@example.com']);
    }

    foreach ([['email' => 'reader6@example.com'], ['ip' => '198.51.100.6', 'email' => 'traveller@example.com']] as $sixth) {
        expect(fn () => cmSubmit($this->item, 'One more', $sixth))->toThrow(function (ValidationException $refused): void {
            expect($refused->status)->toBe(429);
        });
    }

    $this->travel(CommentThrottle::DECAY)->seconds();

    expect(cmSubmit($this->item, 'Later', ['email' => 'reader6@example.com'])->status)->toBe('pending');
});

it('never holds back whoever looks after the item\'s comments with the rate limit (D13)', function (): void {
    foreach (range(1, 6) as $n) {
        expect(cmSubmit($this->item, "Update {$n}", ['actorId' => (string) $this->author->id, 'commenterToken' => null])->status)->toBe('approved');
    }
});

it('stores a plugin moderator\'s verdict, its reason and signals, under the name it gives (I-224)', function (): void {
    app()->bind(CommentModerator::class, fn (): CommentModerator => new class implements CommentModerator
    {
        public function name(): string
        {
            return 'Spam Service';
        }

        public function judge(CommentCandidate $comment): ModerationVerdict
        {
            return new ModerationVerdict(ModerationVerdict::SPAM, 'Scored as spam.', ['score' => 0.97, 'links' => $comment->links]);
        }
    });

    $submitted = cmSubmit($this->item, 'Cheap https://a.example');

    expect($submitted->status)->toBe('spam')
        ->and(Comment::query()->findOrFail($submitted->commentId)->moderation)->toEqual(['by' => 'Spam Service', 'status' => 'spam', 'reason' => 'Scored as spam.', 'signals' => ['score' => 0.97, 'links' => 1]])
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 0, 'pending_count' => 0]);
});

it('holds a comment its moderator gives a status Teme doesn\'t know, and reports it', function (): void {
    Exceptions::fake();
    app()->bind(CommentModerator::class, fn (): CommentModerator => new class implements CommentModerator
    {
        public function name(): string
        {
            return 'Unsure';
        }

        public function judge(CommentCandidate $comment): ModerationVerdict
        {
            return new ModerationVerdict('maybe', 'Not sure.');
        }
    });

    expect(cmSubmit($this->item)->status)->toBe('pending');

    Exceptions::assertReported(UnexpectedValueException::class);
});
