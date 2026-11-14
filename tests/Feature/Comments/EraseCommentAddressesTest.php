<?php

declare(strict_types=1);

use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Operations\EraseCommentAddresses;
use App\Domain\Comments\Operations\Input\EraseCommentAddressesInput;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor, 'Harbour news');
    $this->arrived = fn (int $days, string $status = 'approved', array $more = []): Comment => cmComment($this->item, $status, null, [
        'ip' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Test)',
        'created_at' => now()->subDays($days),
        ...$more,
    ]);
    $this->addresses = fn (Comment $comment): array => (array) DB::table('comments')->where('id', $comment->id)->first(['ip', 'user_agent']);
    $this->rest = fn (Comment $comment): array => (array) DB::table('comments')->where('id', $comment->id)->first(['author_name', 'author_email', 'body', 'status', 'updated_at', 'status_changed_at']);
});

it('erases the IP address and browser of every comment that arrived keep_ip_days ago or earlier, whatever its status, and nothing else (I-270, D13)', function (): void {
    $old = ($this->arrived)(60);
    $oldSpam = ($this->arrived)(90, 'spam');
    $recent = ($this->arrived)(59);
    $before = ($this->rest)($old);

    $this->artisan('teme:comments:erase-addresses')
        ->expectsOutputToContain('Erased the IP address and browser of 2 comments.')
        ->assertSuccessful();

    expect(($this->addresses)($old))->toBe(['ip' => null, 'user_agent' => null])
        ->and(($this->addresses)($oldSpam))->toBe(['ip' => null, 'user_agent' => null])
        ->and(($this->addresses)($recent))->toBe(['ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0 (Test)'])
        ->and(($this->rest)($old))->toBe($before);
});

it('goes by the days the settings keep addresses, a chunk at a time (E2, D13)', function (): void {
    cmSettings(['keep_ip_days' => 7]);

    foreach (range(1, 5) as $n) {
        ($this->arrived)(8);
    }

    $recent = ($this->arrived)(6);

    $this->artisan('teme:comments:erase-addresses', ['--chunk' => 2])
        ->expectsOutputToContain('Erased the IP address and browser of 5 comments.')
        ->assertSuccessful();

    expect(DB::table('comments')->whereNotNull('ip')->pluck('id')->all())->toBe([$recent->id]);
});

it('erases a browser kept without an address, and counts only the comments it changed (D13)', function (): void {
    $browserOnly = ($this->arrived)(61, more: ['ip' => null]);
    ($this->arrived)(61, more: ['ip' => null, 'user_agent' => null]);

    $this->artisan('teme:comments:erase-addresses')
        ->expectsOutputToContain('Erased the IP address and browser of 1 comment.')
        ->assertSuccessful();

    expect(($this->addresses)($browserOnly))->toBe(['ip' => null, 'user_agent' => null]);
});

it('erases only as the operator: a person, or the queue\'s DeniedElevation, is refused and nothing is erased (D13, operations.md)', function (): void {
    $old = ($this->arrived)(90);
    $erase = fn (?string $actorId): int => app(EraseCommentAddresses::class)->execute(new EraseCommentAddressesInput(now()->subDays(60), 1000, $actorId));
    $this->app->instance(Elevation::class, new DeniedElevation);

    expect(fn () => $erase(null))->toThrow(AuthorizationException::class)
        ->and(fn () => $erase((string) $this->editor->id))->toThrow(AuthorizationException::class)
        ->and(($this->addresses)($old)['ip'])->toBe('203.0.113.7');
});

it('is on the schedule (D13)', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('teme:comments:erase-addresses')->assertSuccessful();
});
