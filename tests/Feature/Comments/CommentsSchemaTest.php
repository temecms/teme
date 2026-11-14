<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Comments\Operations\EraseCommentAddresses;
use App\Domain\Comments\Operations\Input\EraseCommentAddressesInput;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('declares which types take comments, and how their new items start (I-274)', function (): void {
    $types = app(ContentTypeRegistry::class);

    expect($types->get('post')->comments)->toBe(Discussion::Open)
        ->and($types->get('page')->comments)->toBe(Discussion::Closed)
        ->and($types->get('region')->comments)->toBeNull();
});

it('refuses a type that would take comments with no page to show them on, or start its items Hidden (I-572)', function (): void {
    $types = app(ContentTypeRegistry::class);

    expect(fn () => $types->register(new ContentType('memo', 'Memo', 'Memos', 'memos', public: false, comments: Discussion::Open)))
        ->toThrow(InvalidArgumentException::class, 'public')
        ->and(fn () => $types->register(new ContentType('notice', 'Notice', 'Notices', 'notices', comments: Discussion::Hidden)))
        ->toThrow(InvalidArgumentException::class, 'Open or Closed');
});

it('opens the existing items of types whose new items start open, and closes the rest, when it adds the column', function (): void {
    $post = lifecycleDraft($this->author, 'Harbour news');
    $page = pgAuthorsPage($this->author, 'About');
    $migration = require database_path('migrations/2026_10_18_100100_add_comment_status_to_contents.php');

    $migration->down();
    $migration->up();

    expect(DB::table('contents')->where('id', $post->id)->value('comment_status'))->toBe('open')
        ->and(DB::table('contents')->where('id', $page->id)->value('comment_status'))->toBe('closed')
        ->and(cmRefusal(fn () => DB::table('contents')->where('id', $post->id)->update(['comment_status' => 'paused'])))->toBe('23514');
});

it('keeps a thread\'s shape in its rows: the ancestors\' IDs and its own, one step a level (I-272)', function (): void {
    $item = cmItem($this->author);
    $id = cmId();

    expect(cmRefusal(fn () => cmRow($item, ['id' => $id, 'depth' => 2])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['id' => $id, 'path' => cmId().'/'])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['id' => $id, 'path' => strtoupper($id).'/'])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['id' => $id])))->toBeNull()
        ->and(DB::scalar("SELECT collation_name FROM information_schema.columns WHERE table_name = 'comments' AND column_name = 'path'"))->toBe('C');
});

it('keeps nothing personal in a tombstone, and words in every other comment (I-278)', function (): void {
    $item = cmItem($this->author);

    expect(cmRefusal(fn () => cmRow($item, ['status' => 'removed'])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['body' => null])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['status' => 'removed', 'author_name' => null, 'author_email' => null, 'body' => null])))->toBeNull();
});

it('refuses a kind it doesn\'t know, and a body or moderation record that isn\'t an object', function (): void {
    $item = cmItem($this->author);

    expect(cmRefusal(fn () => cmRow($item, ['kind' => 'like'])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['body' => '[]'])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['moderation' => '"held"'])))->toBe('23514')
        ->and(cmRefusal(fn () => cmRow($item, ['kind' => 'pingback', 'author_email' => null])))->toBeNull();
});

it('refuses a comment with no created_at or updated_at, which would leave its item\'s last_comment_at unset', function (): void {
    $item = cmItem($this->author);

    expect(cmRefusal(fn () => cmRow($item, ['created_at' => null])))->toBe('23502')
        ->and(cmRefusal(fn () => cmRow($item, ['updated_at' => null])))->toBe('23502')
        ->and(cmRefusal(fn () => cmRow($item)))->toBeNull();
});

it('keeps each imported comment\'s WordPress number once, and refuses a reply to a comment that isn\'t there', function (): void {
    $item = cmItem($this->author);
    cmRow($item, ['legacy_id' => 123]);
    $missing = cmId();
    $id = cmId();

    expect(cmRefusal(fn () => cmRow($item, ['legacy_id' => 123])))->toBe('23505')
        ->and(cmRefusal(fn () => cmRow($item, ['id' => $id, 'parent_id' => $missing, 'path' => "{$missing}/{$id}/", 'depth' => 2])))->toBe('23503');
});

it('reads a thread in display order from one index range, tombstones in their places (D2)', function (): void {
    $indexes = DB::table('pg_indexes')->where('tablename', 'comments')->pluck('indexdef', 'indexname')->all();

    expect(array_keys($indexes))->toEqualCanonicalizing(['comments_pkey', 'comments_thread', 'comments_item_status', 'comments_parent', 'comments_email', 'comments_user', 'comments_legacy', 'comments_conversations', 'comments_token', 'comments_waiting', 'comments_inbox', 'comments_parked', 'comments_addresses'])
        ->and($indexes['comments_thread'])->toContain('(content_id, path)')->toContain("'approved'")->toContain("'removed'")
        ->and($indexes['comments_legacy'])->toContain('UNIQUE')
        ->and($indexes['comments_addresses'])->toContain('(created_at) WHERE')->toContain('ip IS NOT NULL')->toContain('user_agent IS NOT NULL');
});

it('reads each view of the Comments screen, and the badge, from partial indexes of their own statuses (1.8c, D8)', function (): void {
    $comments = DB::table('pg_indexes')->where('tablename', 'comments')->pluck('indexdef', 'indexname')->all();
    $stats = DB::table('pg_indexes')->where('tablename', 'comment_stats')->pluck('indexdef', 'indexname')->all();

    expect($comments['comments_waiting'])->toContain('(id) WHERE')->toContain("'pending'")->not->toContain("'approved'")
        ->and($comments['comments_inbox'])->toContain('(id) WHERE')->toContain("'pending'")->toContain("'approved'")->not->toContain("'spam'")
        ->and($comments['comments_parked'])->toContain('(status, id) INCLUDE (content_id) WHERE')->toContain("'spam'")->toContain("'trash'")->not->toContain("'pending'")
        ->and($stats['comment_stats_waiting'])->toContain('(content_id) INCLUDE (pending_count) WHERE (pending_count > 0)');
});

it('takes an item\'s comments and counts with it when the item is deleted for good, in one statement', function (): void {
    $item = cmItem($this->author);
    $top = cmRow($item);
    $reply = cmId();
    cmRow($item, ['id' => $reply, 'parent_id' => $top, 'path' => "{$top}/{$reply}/", 'depth' => 2]);
    DB::table('comment_stats')->insert(['content_id' => $item->id, 'approved_count' => 2, 'last_comment_id' => $reply, 'last_comment_at' => now()]);
    pageTrash($item, $this->author);

    app(DeleteContentPermanently::class)->execute(new ContentActionInput($item->id, (string) $this->author->id));

    expect(DB::table('comments')->count())->toBe(0)
        ->and(DB::table('comment_stats')->count())->toBe(0);
});

it('finds the addresses to erase from comments_addresses, never by reading every comment (I-270)', function (): void {
    cmRow(cmItem($this->author), ['ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Test)', 'created_at' => now()->subDays(90)]);
    $sent = [];

    DB::listen(function (QueryExecuted $query) use (&$sent): void {
        if (preg_match('/^\s*update\s+comments\b/i', $query->sql) === 1) {
            $sent[] = [$query->sql, $query->bindings];
        }
    });

    $this->app->instance(Elevation::class, new OperatorElevation);
    app(EraseCommentAddresses::class)->execute(new EraseCommentAddressesInput(now()->subDays(60), 1000));

    expect($sent)->toHaveCount(1);

    [$sql, $bindings] = $sent[0];

    // Sequential scans priced out, so the plan shows what an index can serve.
    $plan = DB::transaction(function () use ($sql, $bindings): string {
        DB::statement('SET LOCAL enable_seqscan = off');

        return implode("\n", array_map(fn (object $row): string => (string) $row->{'QUERY PLAN'}, DB::select('EXPLAIN '.$sql, $bindings)));
    });

    expect($plan)->toContain('comments_addresses');
});
