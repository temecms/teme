<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor, 'Harbour news');
    $this->movedAgo = fn (int $days, ?Comment $parent = null, string $status = 'trash'): Comment => cmComment($this->item, $status, $parent, [
        'status_changed_at' => now()->subDays($days)->format('Y-m-d H:i:s.uP'),
    ]);
});

it('deletes permanently the comments in the trash longer than "Keep trash for", and keeps the rest (C4, D8)', function (): void {
    $old = ($this->movedAgo)(31);
    $recent = ($this->movedAgo)(29);
    $spam = ($this->movedAgo)(40, status: 'spam');
    $shown = cmComment($this->item);

    $this->artisan('teme:trash:purge')->expectsOutputToContain('Deleted 1 comment from the trash.')->assertSuccessful();

    expect(Comment::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Comment::query()->whereKey([$recent->id, $spam->id, $shown->id])->count())->toBe(3);
});

it('purges a trashed reply before the comment it answers, and keeps a place for a reply still shown (D8, I-577)', function (): void {
    $top = ($this->movedAgo)(40);
    $reply = ($this->movedAgo)(40, $top);
    $answered = ($this->movedAgo)(40);
    $kept = cmComment($this->item, 'approved', $answered);

    $this->artisan('teme:trash:purge')->expectsOutputToContain('Deleted 3 comments from the trash. 1 tombstone keeps replies in place.')->assertSuccessful();

    expect(Comment::query()->whereKey([$top->id, $reply->id])->exists())->toBeFalse()
        ->and($answered->refresh()->status)->toBe('removed')
        ->and($kept->refresh()->status)->toBe('approved');
});

it('works through the trash in chunks, by the days the settings keep it (C4, D8)', function (): void {
    cmSettings(['trash_days' => 7], 'content');

    foreach (range(1, 5) as $n) {
        ($this->movedAgo)(8);
    }

    $recent = ($this->movedAgo)(6);

    $this->artisan('teme:trash:purge', ['--chunk' => 2])->expectsOutputToContain('Deleted 5 comments from the trash.')->assertSuccessful();

    expect(Comment::query()->where('status', 'trash')->pluck('id')->all())->toBe([$recent->id]);
});

it('tells people that comments follow "Keep trash for" too (C4, C5)', function (): void {
    expect(app(SettingsRegistry::class)->find('content')?->setting('trash_days')?->description)
        ->toBe('Days an item or comment stays in the trash before it’s deleted permanently.');
});
