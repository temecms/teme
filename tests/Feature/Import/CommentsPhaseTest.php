<?php

declare(strict_types=1);

use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Phases\Comments\CommentCountsStep;
use App\Domain\Import\Phases\Comments\CommentPagesStep;
use App\Domain\Import\Phases\Comments\CommentsPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Import\CommentImports;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->companion = importFake();
    $this->source = importSource();
    $this->item = cmItem($this->admin);
    CommentImports::post($this->source, 20, $this->item, '/harbour-news/');
});

it('registers the comments phase at 700 and its steps at 780 and 790, in every kind of run', function (): void {
    $phases = app(ImportPhases::class);

    expect($phases->find('comments'))->toBeInstanceOf(CommentsPhase::class)
        ->and($phases->find('comments')?->order())->toBe(700)
        ->and($phases->find('comments')?->runsIn(ImportRunKind::CatchUp))->toBeTrue()
        ->and($phases->find('comment-counts'))->toBeInstanceOf(CommentCountsStep::class)
        ->and($phases->find('comment-counts')?->order())->toBe(780)
        ->and($phases->find('comment-pages'))->toBeInstanceOf(CommentPagesStep::class)
        ->and($phases->find('comment-pages')?->order())->toBe(790);
});

it('brings each comment as WordPress had it, its reply under it', function (): void {
    $run = CommentImports::run($this->companion, $this->source, [
        CommentImports::item(30),
        CommentImports::item(31, ['parent' => 30, 'author_name' => 'Grace &amp; Co', 'author_email' => '', 'author_url' => 'javascript:alert(1)', 'date_gmt' => '2024-05-01 11:00:00']),
    ], actor: $this->admin);
    $first = CommentImports::comment($this->source, 30);
    $reply = CommentImports::comment($this->source, 31);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($run->created_count)->toBe(2)
        ->and(CommentImports::entry($this->source, 30)->only(['teme_type', 'outcome']))->toBe(['teme_type' => 'comment', 'outcome' => ImportOutcome::Created])
        ->and($first->only(['content_id', 'status', 'author_name', 'author_email', 'author_url', 'legacy_id', 'ip', 'user_agent']))->toBe([
            'content_id' => $this->item->id, 'status' => 'approved', 'author_name' => 'Ada Guest', 'author_email' => 'ada@example.com',
            'author_url' => 'https://ada.example', 'legacy_id' => 30, 'ip' => null, 'user_agent' => null,
        ])
        ->and($first->body)->toEqual(['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lovely piece.']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thanks for writing it.']]],
        ]])
        ->and($reply->parent_id)->toBe($first->id)
        ->and($reply->path)->toBe($first->path.$reply->id.'/')
        ->and($reply->only(['author_name', 'author_email', 'author_url']))->toBe(['author_name' => 'Grace & Co', 'author_email' => null, 'author_url' => null])
        ->and(CommentImports::codes($this->source, 30))->toBe([]);
});

it('keeps WordPress’s status and kind', function (string $status, string $type, string $kept, CommentKind $kind): void {
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['status' => $status, 'type' => $type])]);
    $comment = CommentImports::comment($this->source, 30);

    expect($comment->status)->toBe($kept)
        ->and($comment->kind)->toBe($kind)
        ->and($comment->previous_status)->toBeNull();
})->with([
    'waiting' => ['0', 'comment', 'pending', CommentKind::Comment],
    'approved' => ['1', 'comment', 'approved', CommentKind::Comment],
    'spam' => ['spam', 'comment', 'spam', CommentKind::Comment],
    'in the trash' => ['trash', 'comment', 'trash', CommentKind::Comment],
    'its post in the trash' => ['post-trashed', 'comment', 'trash', CommentKind::Comment],
    'a pingback' => ['1', 'pingback', 'approved', CommentKind::Pingback],
    'a trackback' => ['1', 'trackback', 'approved', CommentKind::Trackback],
]);

it('skips a kind or status no comment has here, saying so', function (array $fields, string $code, string $words): void {
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, $fields)]);
    $entry = CommentImports::entry($this->source, 30);
    $message = ImportMessageRecord::query()->where('map_id', $entry->id)->sole();

    expect($entry->outcome)->toBe(ImportOutcome::Skipped)
        ->and(Comment::query()->count())->toBe(0)
        ->and($message->code)->toBe($code)
        ->and($message->message)->toBe($words);
})->with([
    'a like' => [['type' => 'like'], 'comment.other-type', 'WordPress kept it as a like, which isn’t a reader’s comment, a pingback or a trackback, so it wasn’t imported.'],
    'an archived notification' => [['status' => 'archived'], 'comment.other-status', 'WordPress gives it the status archived, which no comment has on Teme, so it wasn’t imported.'],
]);

it('skips a comment whose post or page didn’t come across, saying why', function (Closure $arrange, string $code, string $words): void {
    $arrange($this);
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['post_id' => 40])]);
    $entry = CommentImports::entry($this->source, 30);

    expect($entry->outcome)->toBe(ImportOutcome::Skipped)
        ->and(Comment::query()->count())->toBe(0)
        ->and(CommentImports::codes($this->source, 30))->toBe([$code])
        ->and(ImportMessageRecord::query()->where('map_id', $entry->id)->value('message'))->toBe($words);
})->with([
    'never read' => [fn ($test) => null, 'comment.item-missing', 'Its post or page (WordPress ID 40) isn’t one this import brings, so neither is it.'],
    'left behind' => [fn ($test) => CommentImports::post($test->source, 40, null, outcome: 'skipped'), 'comment.item-missing', 'Its post or page (WordPress ID 40) isn’t one this import brings, so neither is it.'],
    'a media file’s page' => [
        fn ($test) => ImportRecords::entry($test->source->id, ImportRecords::run($test->source->id), 'attachment', '40', 'created', ['teme_type' => 'media']),
        'comment.on-media',
        'It’s on a media file’s page (WordPress ID 40), and Teme’s media files take no comments, so it wasn’t imported.',
    ],
    'deleted here since' => [
        fn ($test) => ImportRecords::entry($test->source->id, ImportRecords::run($test->source->id), 'post', '40', 'created'),
        'comment.item-deleted-here',
        'Its post or page was deleted on Teme after an earlier import, so it wasn’t imported.',
    ],
]);

it('waits for a post that failed, and brings its comment once the post comes', function (): void {
    $post = CommentImports::post($this->source, 40, null, outcome: 'failed');
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['post_id' => 40])]);

    expect(CommentImports::entry($this->source, 30)->outcome)->toBe(ImportOutcome::Failed)
        ->and(CommentImports::codes($this->source, 30))->toBe(['comment.item-failed'])
        ->and(ImportMessageRecord::query()->where('code', 'comment.item-failed')->sole()->message)->toBe('Its post or page (WordPress ID 40) couldn’t be imported, so it waits for it: a full import tries both again.');

    $later = cmItem($this->admin, 'Ferry times');
    DB::table('import_map')->where('id', $post)->update(['teme_type' => 'content', 'teme_id' => $later->id, 'status' => 'imported', 'outcome' => 'created']);
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['post_id' => 40])]);

    expect(CommentImports::comment($this->source, 30)->content_id)->toBe($later->id);
});

it('starts a conversation of its own when the comment it replied to didn’t come across', function (): void {
    CommentImports::post($this->source, 21, cmItem($this->admin, 'Ferry times'), '/ferry-times/');

    CommentImports::run($this->companion, $this->source, [
        CommentImports::item(30, ['type' => 'like']),
        CommentImports::item(31, ['post_id' => 21]),
        CommentImports::item(32, ['parent' => 30]),
        CommentImports::item(33, ['parent' => 31]),
        CommentImports::item(34, ['parent' => 29]),
    ]);

    expect(collect([32, 33, 34])->map(fn (int $id): int => CommentImports::comment($this->source, $id)->depth)->all())->toBe([1, 1, 1])
        ->and(CommentImports::codes($this->source, 32))->toBe(['comment.parent-missing'])
        ->and(CommentImports::codes($this->source, 33))->toBe(['comment.parent-elsewhere'])
        ->and(CommentImports::codes($this->source, 34))->toBe(['comment.parent-missing'])
        ->and(ImportMessageRecord::query()->where('code', 'comment.parent-elsewhere')->value('message'))
        ->toBe('The comment it replied to (WordPress ID 31) is on another post or page on Teme, so it starts a conversation of its own.');
});

it('places a reply whose comment comes later in WordPress’s order on the next run', function (): void {
    $comments = [CommentImports::item(30, ['parent' => 31]), CommentImports::item(31)];
    $first = CommentImports::run($this->companion, $this->source, $comments);

    expect($first->failed_count)->toBe(1)
        ->and(CommentImports::entry($this->source, 30)->outcome)->toBe(ImportOutcome::Failed)
        ->and(CommentImports::codes($this->source, 30))->toBe(['comment.parent-later']);

    CommentImports::run($this->companion, $this->source, $comments);

    expect(CommentImports::comment($this->source, 30)->parent_id)->toBe(CommentImports::comment($this->source, 31)->id);
});

it('waits for a reply’s comment that failed, then starts a conversation if it still hasn’t come', function (): void {
    CommentImports::post($this->source, 40, null, outcome: 'failed');
    $comments = [CommentImports::item(30, ['post_id' => 40]), CommentImports::item(31, ['parent' => 30])];

    CommentImports::run($this->companion, $this->source, $comments);

    expect(CommentImports::codes($this->source, 31))->toBe(['comment.parent-failed']);

    CommentImports::run($this->companion, $this->source, $comments);

    expect(CommentImports::comment($this->source, 31)->depth)->toBe(1)
        ->and(CommentImports::codes($this->source, 31))->toBe(['comment.parent-missing'])
        ->and(CommentImports::codes($this->source, 30))->toBe(['comment.item-failed']);
});

it('settles two comments that reply to each other within two runs', function (): void {
    $comments = [CommentImports::item(30, ['parent' => 31]), CommentImports::item(31, ['parent' => 30])];

    CommentImports::run($this->companion, $this->source, $comments);
    $second = CommentImports::run($this->companion, $this->source, $comments);

    expect($second->failed_count)->toBe(0)
        ->and(CommentImports::comment($this->source, 30)->depth)->toBe(1)
        ->and(CommentImports::comment($this->source, 31)->parent_id)->toBe(CommentImports::comment($this->source, 30)->id);
});

it('places a reply more than 50 levels deep beside the comment it answered', function (): void {
    $chain = array_map(fn (int $id): array => CommentImports::item($id, ['parent' => $id - 1]), range(1, 52));

    CommentImports::run($this->companion, $this->source, $chain);
    $fiftieth = CommentImports::comment($this->source, 50);
    $beside = CommentImports::comment($this->source, 51);

    expect($fiftieth->depth)->toBe(50)
        ->and($beside->depth)->toBe(50)
        ->and($beside->parent_id)->toBe(CommentImports::comment($this->source, 49)->id)
        ->and(CommentImports::codes($this->source, 51))->toBe(['comment.too-deep'])
        ->and(CommentImports::comment($this->source, 52)->depth)->toBe(50);
});

it('ties a comment to the person its writer’s account became, and keeps a subscriber’s as a guest’s', function (): void {
    $grace = User::factory()->create()->assignRole('author');
    $earlier = ImportRecords::run($this->source->id);
    ImportRecords::entry($this->source->id, $earlier, 'user', '7', 'created', ['teme_type' => 'user', 'teme_id' => (string) $grace->id]);
    ImportRecords::entry($this->source->id, $earlier, 'user', '8', 'skipped');

    CommentImports::run($this->companion, $this->source, [
        CommentImports::item(30, ['user_id' => 7]),
        CommentImports::item(31, ['user_id' => 8, 'author_name' => 'Sam Subscriber', 'author_email' => 'sam@old.example']),
    ]);

    expect(CommentImports::comment($this->source, 30)->author_user_id)->toBe($grace->id)
        ->and(CommentImports::comment($this->source, 31)->only(['author_user_id', 'author_name', 'author_email']))
        ->toBe(['author_user_id' => null, 'author_name' => 'Sam Subscriber', 'author_email' => 'sam@old.example']);
});

it('dates a comment WordPress gave no date with its item’s, saying so', function (): void {
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['date_gmt' => '0000-00-00 00:00:00'])]);

    // Instants, both read with their offsets, never strings in the session's zone.
    expect(CommentImports::comment($this->source, 30)->created_at
        ?->equalTo(CarbonImmutable::parse((string) $this->item->getRawOriginal('published_at'))->startOfSecond()))->toBeTrue()
        ->and(CommentImports::codes($this->source, 30))->toBe(['comment.no-date']);
});

it('says when another comment already holds its WordPress number', function (): void {
    cmRow($this->item, ['legacy_id' => 30]);

    CommentImports::run($this->companion, $this->source, [CommentImports::item(30)]);

    expect(CommentImports::comment($this->source, 30)->legacy_id)->toBeNull()
        ->and(ImportMessageRecord::query()->where('code', 'comment.anchor-taken')->value('message'))
        ->toBe('Another comment on Teme already has its WordPress number, so old links to #comment-30 won’t find it.');
});

it('fails a comment too long to keep, and brings the rest', function (): void {
    $run = CommentImports::run($this->companion, $this->source, [
        CommentImports::item(30, ['content' => implode("\n", array_fill(0, 1100, 'again'))]),
        CommentImports::item(31),
    ]);

    expect($run->failed_count)->toBe(1)
        ->and(CommentImports::codes($this->source, 30))->toBe(['object.refused'])
        ->and(ImportMessageRecord::query()->where('code', 'object.refused')->value('message'))->toContain('A comment holds at most 2000 parts.')
        ->and(CommentImports::comment($this->source, 31)->legacy_id)->toBe(31);
});

it('reads the comments a page of 500 at a time, and a rerun makes none twice', function (bool $busy): void {
    $comments = array_map(fn (int $id): array => CommentImports::item($id), range(1, 501));

    if ($busy) {
        // Each statement takes a second, so a page job runs out of its time part-way through a page.
        DB::listen(fn () => $this->travel(1)->seconds());
    } else {
        // The clock stands still, so no page job runs out of its time, however slow the machine.
        $this->freezeSecond();
    }

    // The pages asked for, as after_id and limit; the fake records every query value as a string (FakeCompanion::respond()).
    $asked = fn (): array => array_map(fn (array $request): array => [(int) $request['query']['after_id'], (int) $request['query']['limit']], $this->companion->requestsTo('comments'));
    $first = CommentImports::run($this->companion, $this->source, $comments);
    $firstPages = $asked();
    $second = CommentImports::run($this->companion, $this->source, $comments);
    $secondPages = array_slice($asked(), count($firstPages));

    // A job out of time leaves the rest of its page to the next, which asks again from the last comment done, so
    // which pages are asked for depends on the machine's speed (#316); each run still reads from the first comment
    // to the last, 500 at a time, always onward.
    foreach ([$firstPages, $secondPages] as $pages) {
        $after = array_column($pages, 0);
        $sorted = $after;
        sort($sorted);

        expect(array_values(array_unique(array_column($pages, 1))))->toBe([500])
            ->and($after[0])->toBe(0)
            ->and($after)->toBe(array_values(array_unique($sorted)))
            ->and($after[count($after) - 1])->toBeGreaterThanOrEqual(1)
            ->and($after[count($after) - 1] + 500)->toBeGreaterThanOrEqual(501);
    }

    if (! $busy) {
        expect([...$firstPages, ...$secondPages])->toBe([[0, 500], [500, 500], [0, 500], [500, 500]]);
    }

    expect(Comment::query()->count())->toBe(501)
        ->and(ImportMapEntry::query()->where('object_type', 'comment')->where('run_id', $first->id)->count())->toBe(501)
        ->and($second->created_count + $second->updated_count + $second->skipped_count + $second->failed_count)->toBe(0);
})->with(['at full speed' => false, 'on a machine too busy to finish a page in one job' => true]);

it('follows WordPress’s change to a comment the import left as it was', function (): void {
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['status' => '0'])]);
    $this->travel(1)->hours();

    $run = CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['status' => '1', 'content' => 'Lovely piece, truly.'])]);
    $comment = CommentImports::comment($this->source, 30);

    expect($comment->status)->toBe('approved')
        ->and(CommentText::plain($comment->body ?? []))->toBe('Lovely piece, truly.')
        ->and($run->updated_count)->toBe(1)
        ->and(CommentImports::entry($this->source, 30)->outcome)->toBe(ImportOutcome::Updated)
        ->and(CommentImports::entry($this->source, 30)->facts['status'] ?? null)->toBe('approved')
        ->and(Comment::query()->count())->toBe(1);
});

it('leaves a comment someone changed here, saying so', function (): void {
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['status' => '0'])]);
    cmModerate(CommentImports::comment($this->source, 30), 'approved', $this->admin);

    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['status' => 'spam'])]);

    expect(CommentImports::comment($this->source, 30)->status)->toBe('approved')
        ->and(CommentImports::codes($this->source, 30))->toBe(['comment.changed-here']);
});

it('leaves a comment deleted here deleted', function (): void {
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['status' => 'spam'])]);
    cmDelete(CommentImports::comment($this->source, 30), $this->admin);

    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, ['status' => '1'])]);

    expect(Comment::query()->count())->toBe(0)
        ->and(CommentImports::entry($this->source, 30)->outcome)->toBe(ImportOutcome::Skipped)
        ->and(CommentImports::codes($this->source, 30))->toBe(['comment.deleted-here']);
});

it('rebuilds the item’s counts once, after its comments', function (): void {
    CommentImports::run($this->companion, $this->source, [
        CommentImports::item(30),
        CommentImports::item(31, ['parent' => 30]),
        CommentImports::item(32, ['status' => '0']),
    ]);

    expect(cmStats($this->item))->toMatchArray([
        'approved_count' => 2, 'pending_count' => 1, 'thread_count' => 1,
        'last_comment_id' => CommentImports::comment($this->source, 31)->id,
    ]);
});

it('never puts a commenter’s email address, IP address, browser or words in a message', function (): void {
    CommentImports::run($this->companion, $this->source, [CommentImports::item(30, [
        'parent' => 29, 'date_gmt' => '', 'content' => '<a href="/nowhere/">Ada@Example.com said 203.0.113.7</a> <u>Mozilla</u>',
    ])]);
    $messages = ImportMessageRecord::query()->get();

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        expect(mb_strtolower($message->message.json_encode($message->context, JSON_THROW_ON_ERROR)))
            ->not->toContain('ada@example.com')
            ->not->toContain('203.0.113.7')
            ->not->toContain('mozilla')
            ->not->toContain('said');
    }
});
