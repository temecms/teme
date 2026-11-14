<?php

declare(strict_types=1);

use App\Domain\Comments\Operations\ModerateComment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor);
});

it('counts the conversations an item\'s pages show: the comments that start a thread, approved (D2)', function (): void {
    $first = cmComment($this->item);
    cmComment($this->item, parent: $first);
    cmComment($this->item, 'pending');
    cmComment($this->item, 'spam');

    expect(cmStats($this->item))->toMatchArray(['approved_count' => 2, 'pending_count' => 1, 'thread_count' => 1]);
});

it('moves the count as a conversation\'s first comment leaves the thread and comes back, and keeps it for its tombstone (I-278)', function (): void {
    $top = cmComment($this->item);
    cmComment($this->item, parent: $top);

    cmModerate($top, 'trash', $this->editor);

    expect(cmStats($this->item)['thread_count'] ?? null)->toBe(0);

    cmModerate($top, ModerateComment::RESTORE, $this->editor);

    expect(cmStats($this->item)['thread_count'] ?? null)->toBe(1);

    cmModerate($top, 'spam', $this->editor);

    expect(cmDelete($top->refresh(), $this->editor))->toBe('removed')
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 1, 'thread_count' => 1]);
});

it('lowers the count when a tombstone left holding nothing goes with the last comment under it (I-577)', function (): void {
    $top = cmComment($this->item);
    $reply = cmComment($this->item, parent: $top);
    cmModerate($top, 'trash', $this->editor);
    cmDelete($top->refresh(), $this->editor);
    cmModerate($reply, 'trash', $this->editor);

    expect(cmDelete($reply->refresh(), $this->editor))->toBe('deleted')
        ->and(DB::table('comments')->count())->toBe(0)
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 0, 'thread_count' => 0]);
});

it('counts the conversations items already had when it adds the column, and finds them by position in an index of their own', function (): void {
    $top = cmComment($this->item);
    cmComment($this->item, parent: $top);
    cmComment($this->item);
    $migration = require database_path('migrations/2026_10_19_100000_add_thread_count_to_comment_stats.php');

    $migration->down();
    $migration->up();

    expect(cmStats($this->item)['thread_count'] ?? null)->toBe(2)
        ->and((string) DB::scalar("SELECT indexdef FROM pg_indexes WHERE indexname = 'comments_conversations'"))
        ->toContain('(content_id, path)')->toContain('depth = 1')->toContain("'removed'");
});
