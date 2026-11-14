<?php

declare(strict_types=1);

use App\Domain\Comments\CommentKind;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\ImportComment;
use App\Domain\Comments\Operations\Input\ImportCommentInput;
use App\Domain\Comments\Operations\Input\UpdateImportedCommentInput;
use App\Domain\Comments\Operations\UpdateImportedComment;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Uid\Ulid;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->importer = User::factory()->create()->assignRole('administrator');
    $this->item = cmItem($this->importer);
});

/**
 * Imports one comment on $item as $actor: a guest's approved comment of
 * 1 May 2024 at 10:00 UTC, WordPress number 30, but for $fields. As the
 * operator at the shell when $actor is null and $elevated holds.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array{commentId: string, legacyKept: bool, editedAt: CarbonImmutable}
 */
function ikImport(Content $item, array $fields, ?User $actor, bool $elevated = true): array
{
    $import = fn (): array => app(ImportComment::class)->execute(new ImportCommentInput(...[
        'contentId' => $item->id,
        'parentId' => null,
        'kind' => CommentKind::Comment,
        'status' => 'approved',
        'writtenAt' => CarbonImmutable::parse('2024-05-01 10:00:00', 'UTC'),
        'legacyId' => 30,
        'authorName' => 'Ada Guest',
        'authorEmail' => 'ada@example.com',
        'authorUrl' => 'https://ada.example',
        'authorUserId' => null,
        'body' => cmBody('Lovely piece.'),
        'ip' => '203.0.113.7',
        'userAgent' => 'Mozilla/5.0',
        'actorId' => $actor === null ? null : (string) $actor->id,
        ...$fields,
    ]));

    return $actor === null && $elevated ? scElevated($import) : $import();
}

it('writes a WordPress comment as WordPress had it, judging nothing and sending nothing', function (): void {
    Mail::fake();
    Queue::fake();

    $result = ikImport($this->item, [], $this->importer);
    $comment = Comment::query()->findOrFail($result['commentId']);

    expect($comment->only(['content_id', 'parent_id', 'depth', 'status', 'previous_status', 'author_name', 'author_email', 'author_url', 'author_user_id', 'legacy_id', 'moderation']))->toBe([
        'content_id' => $this->item->id, 'parent_id' => null, 'depth' => 1, 'status' => 'approved', 'previous_status' => null,
        'author_name' => 'Ada Guest', 'author_email' => 'ada@example.com', 'author_url' => 'https://ada.example',
        'author_user_id' => null, 'legacy_id' => 30, 'moderation' => null,
    ])
        ->and($comment->kind)->toBe(CommentKind::Comment)
        ->and($comment->path)->toBe($comment->id.'/')
        ->and($comment->body)->toEqual(cmBody('Lovely piece.'))
        ->and($comment->created_at?->equalTo(CarbonImmutable::parse('2024-05-01 10:00:00', 'UTC')))->toBeTrue()
        ->and($comment->updated_at?->equalTo($result['editedAt']))->toBeTrue()
        ->and(cmChangedAt($comment)->greaterThan(CarbonImmutable::now()->subMinute()))->toBeTrue()
        ->and($result['legacyKept'])->toBeTrue()
        ->and(DB::table('comment_stats')->count())->toBe(0);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('lets the operator import at the shell', function (): void {
    expect(Comment::query()->find(ikImport($this->item, [], null)['commentId']))->not->toBeNull();
});

it('makes its ID from WordPress’s date and number, so one second keeps WordPress’s order', function (): void {
    $second = CarbonImmutable::parse('2024-05-01 10:00:00', 'UTC');
    $later = ikImport($this->item, ['legacyId' => 31, 'writtenAt' => $second], $this->importer)['commentId'];
    $earlier = ikImport($this->item, ['legacyId' => 30, 'writtenAt' => $second], $this->importer)['commentId'];

    expect($earlier)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and(Ulid::fromString($earlier)->getDateTime()->getTimestamp())->toBe($second->getTimestamp())
        ->and(strcmp($earlier, $later))->toBeLessThan(0)
        ->and(Comment::query()->where('content_id', $this->item->id)->orderBy('path')->pluck('legacy_id')->all())->toBe([30, 31]);
});

it('places a reply under the comment it answered, however deep the settings allow', function (): void {
    cmSettings(['thread_depth' => 2]);
    $parent = null;

    foreach (range(1, 6) as $number) {
        $parent = ikImport($this->item, [
            'legacyId' => $number,
            'parentId' => $parent,
            'writtenAt' => CarbonImmutable::parse('2024-05-01 10:00:00', 'UTC')->addMinutes($number),
        ], $this->importer)['commentId'];
    }

    $deepest = Comment::query()->findOrFail($parent);

    expect($deepest->depth)->toBe(6)
        ->and(substr_count($deepest->path, '/'))->toBe(6)
        ->and(Comment::query()->findOrFail($deepest->parent_id)->depth)->toBe(5);
});

it('keeps the IP address and browser only while keep_ip_days allows', function (int $daysAgo, int $keep, bool $kept): void {
    cmSettings(['keep_ip_days' => $keep]);
    $id = ikImport($this->item, ['writtenAt' => CarbonImmutable::now()->subDays($daysAgo)->startOfSecond()], $this->importer)['commentId'];
    $row = DB::table('comments')->where('id', $id)->first(['ip', 'user_agent']);

    expect($row->ip)->toBe($kept ? '203.0.113.7' : null)
        ->and($row->user_agent)->toBe($kept ? 'Mozilla/5.0' : null);
})->with([
    'ten days old, kept for 60' => [10, 60, true],
    'sixty-one days old, kept for 60' => [61, 60, false],
    'sixty-one days old, kept for 90' => [61, 90, true],
]);

it('never keeps an IP address that isn’t one, and cuts a browser at 255 characters', function (): void {
    $id = ikImport($this->item, ['writtenAt' => CarbonImmutable::now()->subDay()->startOfSecond(), 'ip' => 'unknown', 'userAgent' => str_repeat('é', 300)], $this->importer)['commentId'];
    $row = DB::table('comments')->where('id', $id)->first(['ip', 'user_agent']);

    expect($row->ip)->toBeNull()
        ->and(mb_strlen((string) $row->user_agent))->toBe(255);
});

it('keeps no old number another comment already holds', function (): void {
    $first = ikImport($this->item, [], $this->importer);
    $second = ikImport($this->item, [], $this->importer);

    expect($first['legacyKept'])->toBeTrue()
        ->and($second['legacyKept'])->toBeFalse()
        ->and(Comment::query()->findOrFail($second['commentId'])->legacy_id)->toBeNull()
        ->and($second['commentId'])->not->toBe($first['commentId']);
});

it('refuses what no imported comment may be', function (Closure $fields, string $words): void {
    $act = fn () => ikImport($this->item, $fields($this), $this->importer);

    expect($act)->toThrow(ValidationException::class, $words)
        ->and(Comment::query()->where('legacy_id', 30)->exists())->toBeFalse();
})->with([
    'a status' => [fn (): array => ['status' => 'removed'], 'A comment’s status is pending, approved, spam or trash.'],
    'a number' => [fn (): array => ['legacyId' => 0], 'A WordPress comment number is a whole number above 0.'],
    'a date' => [fn (): array => ['writtenAt' => CarbonImmutable::parse('1969-12-31 23:59:59', 'UTC')], 'A comment’s date is after 1 January 1970.'],
    'a person' => [fn (): array => ['authorUserId' => 999999], 'The person who wrote it isn’t on Teme.'],
    'its words' => [fn (): array => ['body' => ['type' => 'doc', 'content' => [['type' => 'heading', 'content' => []]]]], 'A comment holds paragraphs and quotes, with no settings.'],
    'an item' => [fn (): array => ['contentId' => strtolower((string) Str::ulid())], 'The post or page isn’t there to comment on.'],
    'a parent on another item' => [
        fn ($test): array => ['parentId' => ikImport(cmItem($test->importer, 'Ferry times'), ['legacyId' => 99], $test->importer)['commentId']],
        'The comment it replies to isn’t on the same post or page.',
    ],
]);

it('writes nothing for anyone who may not import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ikImport($this->item, [], $editor))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(fn () => ikImport($this->item, [], null, elevated: false))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(Comment::query()->count())->toBe(0);
});

/**
 * A later run's update of the comment $imported made, as $actor: WordPress's
 * comment unchanged, the import's record a waiting one, but for $fields.
 *
 * @param  array{commentId: string, legacyKept: bool, editedAt: CarbonImmutable}  $imported
 * @param  array<string, mixed>  $fields
 *
 * @return array{changedHere: bool, editedAt: CarbonImmutable}
 */
function ikUpdate(array $imported, array $fields, User $actor): array
{
    return app(UpdateImportedComment::class)->execute(new UpdateImportedCommentInput(...[
        'commentId' => $imported['commentId'],
        'expectedStatus' => 'pending',
        'expectedEditedAt' => $imported['editedAt'],
        'status' => 'pending',
        'authorName' => 'Ada Guest',
        'authorEmail' => 'ada@example.com',
        'authorUrl' => 'https://ada.example',
        'authorUserId' => null,
        'body' => cmBody('Lovely piece.'),
        'actorId' => (string) $actor->id,
        ...$fields,
    ]));
}

it('follows WordPress while the comment is as the import left it', function (): void {
    $imported = ikImport($this->item, ['status' => 'pending'], $this->importer);
    $this->travel(1)->hours();

    $result = ikUpdate($imported, ['status' => 'approved', 'authorName' => 'Ada Lovelace', 'body' => cmBody('Lovely piece, truly.')], $this->importer);
    $comment = Comment::query()->findOrFail($imported['commentId']);

    expect($result['changedHere'])->toBeFalse()
        ->and($comment->status)->toBe('approved')
        ->and($comment->author_name)->toBe('Ada Lovelace')
        ->and($comment->body)->toEqual(cmBody('Lovely piece, truly.'))
        ->and($comment->updated_at?->equalTo($result['editedAt']))->toBeTrue()
        ->and($result['editedAt']->greaterThan($imported['editedAt']))->toBeTrue()
        ->and(cmChangedAt($comment)->greaterThan(CarbonImmutable::now()->subMinute()))->toBeTrue()
        ->and($comment->only(['legacy_id', 'path', 'depth']))->toBe(['legacy_id' => 30, 'path' => $imported['commentId'].'/', 'depth' => 1]);
});

it('leaves a comment a moderator changed here as it is', function (): void {
    $imported = ikImport($this->item, ['status' => 'pending'], $this->importer);
    cmModerate(Comment::query()->findOrFail($imported['commentId']), 'spam', $this->importer);

    $result = ikUpdate($imported, ['status' => 'approved', 'body' => cmBody('Changed on WordPress.')], $this->importer);
    $comment = Comment::query()->findOrFail($imported['commentId']);

    expect($result['changedHere'])->toBeTrue()
        ->and($comment->status)->toBe('spam')
        ->and($comment->body)->toEqual(cmBody('Lovely piece.'));
});

it('remembers where a comment WordPress put in the trash came from', function (): void {
    $imported = ikImport($this->item, ['status' => 'approved'], $this->importer);

    ikUpdate($imported, ['expectedStatus' => 'approved', 'status' => 'trash'], $this->importer);

    expect(Comment::query()->findOrFail($imported['commentId'])->only(['status', 'previous_status']))->toBe(['status' => 'trash', 'previous_status' => 'approved']);
});

it('updates nothing for anyone who may not import', function (): void {
    $imported = ikImport($this->item, ['status' => 'pending'], $this->importer);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => ikUpdate($imported, ['status' => 'approved'], $editor))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(Comment::query()->findOrFail($imported['commentId'])->status)->toBe('pending');
});

it('turns a refusal of its row into words that hold none of the comment', function (string $operation): void {
    $grace = User::factory()->create();
    $imported = $operation === 'update' ? ikImport($this->item, ['status' => 'pending'], $this->importer) : null;
    // The writer's account goes between the checks and the write, as a deletion elsewhere could.
    Comment::saving(function () use ($grace): void {
        DB::table('users')->where('id', $grace->id)->delete();
    });

    $act = fn (): array => $imported === null
        ? ikImport($this->item, ['authorUserId' => $grace->id], $this->importer)
        : ikUpdate($imported, ['authorUserId' => $grace->id], $this->importer);

    expect($act)->toThrow(function (RuntimeException $refused): void {
        expect($refused->getMessage())->toBe('PostgreSQL refused the comment’s row (23503).')
            ->and($refused->getPrevious())->toBeNull();
    });
})->with(['import', 'update']);
