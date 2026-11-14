<?php

declare(strict_types=1);

use App\Domain\Comments\Operations\Input\RebuildCommentStatsInput;
use App\Domain\Comments\Operations\RebuildCommentStats;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->importer = User::factory()->create()->assignRole('administrator');
    $this->item = cmItem($this->importer);
    // Counts no Operation kept: nine approved comments that aren't there.
    DB::table('comment_stats')->updateOrInsert(['content_id' => $this->item->id], ['approved_count' => 9, 'pending_count' => 0, 'thread_count' => 0]);
});

function rcsRebuild(User $actor): int
{
    return app(RebuildCommentStats::class)->execute(new RebuildCommentStatsInput((string) $actor->id));
}

it('rebuilds each item’s counts, conversations and last comment from the rows', function (): void {
    $first = cmRow($this->item);
    $this->travel(1)->minutes();
    $reply = cmId();
    cmRow($this->item, ['id' => $reply, 'parent_id' => $first, 'path' => $first.'/'.$reply.'/', 'depth' => 2]);
    $this->travel(1)->minutes();
    cmRow($this->item, ['status' => 'pending']);

    expect(rcsRebuild($this->importer))->toBe(1)
        ->and(cmStats($this->item))->toMatchArray(['approved_count' => 2, 'pending_count' => 1, 'thread_count' => 1, 'last_comment_id' => $reply])
        ->and(rcsRebuild($this->importer))->toBe(0);
});

it('rebuilds nothing for anyone who may not import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => rcsRebuild($editor))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(cmStats($this->item)['approved_count'] ?? null)->toBe(9);
});
