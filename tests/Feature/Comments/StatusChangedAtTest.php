<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor, 'Harbour news');
});

it('records when a comment arrives as when its status began, to the microsecond (C4, I-645)', function (): void {
    $arrivedAt = CarbonImmutable::now()->addDay()->setTime(9, 30, 0, 250000);
    $this->travelTo($arrivedAt);

    $submitted = cmSubmit($this->item);

    expect(cmChangedAt(Comment::query()->findOrFail($submitted->commentId))->format('Y-m-d H:i:s.u'))->toBe($arrivedAt->format('Y-m-d H:i:s.u'));
});

it('moves with every change of status, and stays put when the status asked for is the one it has (C4, D2)', function (): void {
    $comment = cmComment($this->item, 'pending');
    $approvedAt = CarbonImmutable::now()->addDay()->setTime(9, 30, 0, 123456);

    $this->travelTo($approvedAt);
    cmModerate($comment, 'approved', $this->editor);

    $this->travel(5)->minutes();
    cmModerate($comment, 'approved', $this->editor);
    $unmoved = cmChangedAt($comment);

    cmModerate($comment, 'trash', $this->editor);

    expect($unmoved->format('Y-m-d H:i:s.u'))->toBe($approvedAt->format('Y-m-d H:i:s.u'))
        ->and(cmChangedAt($comment)->equalTo(CarbonImmutable::now()))->toBeTrue();
});

it('moves when a comment deleted permanently keeps its place as a tombstone (C4, I-577)', function (): void {
    $answered = cmComment($this->item, 'trash');
    cmComment($this->item, 'approved', $answered);
    $removedAt = CarbonImmutable::now()->addDay()->setTime(9, 30, 0, 500000);
    $this->travelTo($removedAt);

    expect(cmDelete($answered, $this->editor))->toBe('removed')
        ->and(cmChangedAt($answered)->format('Y-m-d H:i:s.u'))->toBe($removedAt->format('Y-m-d H:i:s.u'));
});

it('is filled in from when each comment was last updated, then required, with no default (C4, D2)', function (): void {
    $comment = cmComment($this->item, 'trash');
    DB::table('comments')->where('id', $comment->id)->update(['updated_at' => '2026-09-01 08:00:00+00']);
    $migration = require database_path('migrations/2026_10_22_100000_add_status_changed_at_to_comments.php');

    $migration->down();
    $migration->up();

    $column = DB::selectOne("SELECT is_nullable, datetime_precision, column_default FROM information_schema.columns WHERE table_name = 'comments' AND column_name = 'status_changed_at'");

    expect(cmChangedAt($comment)->equalTo(CarbonImmutable::parse('2026-09-01 08:00:00+00')))->toBeTrue()
        ->and([$column->is_nullable, (int) $column->datetime_precision, $column->column_default])->toBe(['NO', 6, null])
        ->and(cmRefusal(fn () => cmRow($this->item, ['status_changed_at' => null])))->toBe('23502');
});
