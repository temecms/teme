<?php

declare(strict_types=1);

use App\Domain\Comments\CommentStats;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->item = cmItem(User::factory()->create()->assignRole('author'));
});

it('counts an item\'s approved and pending comments on one row, made with its first comment (I-273)', function (): void {
    expect(cmStats($this->item))->toBeNull();

    cmComment($this->item);
    cmComment($this->item, 'pending');
    cmComment($this->item, 'spam');

    expect(cmStats($this->item))->toMatchArray(['approved_count' => 1, 'pending_count' => 1]);
});

it('moves a comment\'s count with its status, and counts spam, trash and tombstones nowhere', function (): void {
    $comment = cmComment($this->item, 'pending');

    foreach ([['pending', 'approved', 1, 0], ['approved', 'trash', 0, 0], ['trash', 'pending', 0, 1], ['pending', 'spam', 0, 0]] as [$from, $to, $approved, $pending]) {
        $comment->update(['status' => $to]);
        CommentStats::apply($comment, $from, $to);

        expect(cmStats($this->item))->toMatchArray(['approved_count' => $approved, 'pending_count' => $pending]);
    }
});

it('keeps the newest approved comment as the item\'s last, and the one before it when it leaves (I-273)', function (): void {
    $first = cmComment($this->item);
    $this->travel(5)->minutes();
    $second = cmComment($this->item);
    cmComment($this->item, 'pending');

    expect(cmStats($this->item)['last_comment_id'] ?? null)->toBe($second->id)
        ->and(strtotime((string) (cmStats($this->item)['last_comment_at'] ?? '')))->toBe($second->created_at?->getTimestamp());

    $second->update(['status' => 'trash']);
    CommentStats::apply($second, 'approved', 'trash');

    expect(cmStats($this->item)['last_comment_id'] ?? null)->toBe($first->id);

    $first->update(['status' => 'spam']);
    CommentStats::apply($first, 'approved', 'spam');

    expect(cmStats($this->item))->toMatchArray(['approved_count' => 0, 'last_comment_id' => null, 'last_comment_at' => null]);
});

it('never lets an older comment approved later take a newer one\'s place as the last', function (): void {
    $older = cmComment($this->item, 'pending');
    $this->travel(5)->minutes();
    $newer = cmComment($this->item);

    $older->update(['status' => 'approved']);
    CommentStats::apply($older, 'pending', 'approved');

    expect(cmStats($this->item)['last_comment_id'] ?? null)->toBe($newer->id);
});
