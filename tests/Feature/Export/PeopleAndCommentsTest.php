<?php

declare(strict_types=1);

use App\Domain\Export\Kinds\CommentsKind;
use App\Domain\Export\Kinds\PeopleKind;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Export\ExportAs;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Ada Editor', 'email' => 'ada@example.org', 'bio' => 'Edits the harbour pages.'])->assignRole('editor');
    $this->line = fn (array $lines, int|string $id): array => collect($lines)->firstWhere('id', $id) ?? [];
});

it('carries every person with their email address, roles and state', function (): void {
    $invited = User::factory()->invited()->create(['email' => 'new@example.org'])->assignRole('author');
    $suspended = User::factory()->suspended()->create(['email' => 'gone@example.org'])->assignRole('contributor');

    $lines = ExportAs::kindLines(app(PeopleKind::class));
    $ada = ($this->line)($lines, $this->editor->id);

    expect($ada)->toBe([
        'id' => $this->editor->id,
        'name' => 'Ada Editor',
        'email' => 'ada@example.org',
        'slug' => $this->editor->refresh()->slug,
        'bio' => 'Edits the harbour pages.',
        'roles' => ['editor'],
        'state' => 'active',
        'created_at' => $ada['created_at'],
    ])
        ->and(($this->line)($lines, $invited->id)['state'])->toBe('invited')
        ->and(($this->line)($lines, $suspended->id)['state'])->toBe('suspended')
        ->and(($this->line)($lines, $suspended->id)['roles'])->toBe(['contributor']);
});

it('never carries a password hash, a remember-me token or a two-factor value (I-2408)', function (): void {
    $guarded = User::factory()->withTwoFactor()->create(['remember_token' => 'remember-me-token-1234']);
    $stored = DB::table('users')->where('id', $guarded->id)->first(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token']);

    $written = json_encode(ExportAs::kindLines(app(PeopleKind::class)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($stored->two_factor_secret)->not->toBeNull()
        ->and($written)->not->toContain((string) $stored->password)
        ->and($written)->not->toContain((string) $stored->two_factor_secret)
        ->and($written)->not->toContain((string) $stored->two_factor_recovery_codes)
        ->and($written)->not->toContain('remember-me-token-1234')
        ->and($written)->not->toContain('"password"')
        ->and($written)->not->toContain('two_factor');
});

it('carries each comment’s words and its commenter’s name, email and website, never their IP address or browser (I-2408)', function (): void {
    $item = cmItem($this->editor);
    $comment = cmComment($item, 'approved', null, [
        'author_url' => 'https://ada.example.org',
        'ip' => '203.0.113.9',
        'user_agent' => 'Mozilla/5.0 (Harbour)',
        'commenter_token_hash' => str_repeat('f', 64),
        'moderation' => ['by' => 'built-in', 'reason' => 'Looks fine'],
    ]);

    $lines = ExportAs::kindLines(app(CommentsKind::class));
    $line = ($this->line)($lines, $comment->id);
    $written = json_encode($lines, JSON_UNESCAPED_SLASHES);

    expect($line['content_id'])->toBe($item->id)
        ->and($line['parent_id'])->toBeNull()
        ->and($line['comment_kind'])->toBe('comment')
        ->and($line['status'])->toBe('approved')
        ->and($line['author_name'])->toBe('Ada Guest')
        ->and($line['author_email'])->toBe('ada@example.com')
        ->and($line['author_url'])->toBe('https://ada.example.org')
        // JSONB orders an object's keys its own way, so compare values, not bytes.
        ->and(json_decode((string) json_encode($line['body']), true))->toEqual(cmBody('Lovely piece.'))
        ->and(array_keys($line))->not->toContain('ip')
        ->and($written)->not->toContain('203.0.113.9')
        ->and($written)->not->toContain('Mozilla')
        ->and($written)->not->toContain(str_repeat('f', 64))
        ->and($written)->not->toContain('Looks fine');
});

it('leaves spam and the comments on items in the trash out, and keeps a removed comment’s place for its replies', function (): void {
    $item = cmItem($this->editor);
    $trashed = cmItem($this->editor, 'Gone');
    $removed = cmComment($item, 'removed', null, ['author_name' => null, 'author_email' => null, 'body' => null]);
    $reply = cmComment($item, 'pending', $removed);
    $spam = cmComment($item, 'spam');
    $onTrashed = cmComment($trashed, 'approved');
    pageTrash($trashed, $this->editor);

    $lines = ExportAs::kindLines(app(CommentsKind::class));
    $ids = array_column($lines, 'id');

    expect($ids)->toContain($removed->id)
        ->and($ids)->toContain($reply->id)
        ->and($ids)->not->toContain($spam->id)
        ->and($ids)->not->toContain($onTrashed->id)
        ->and(($this->line)($lines, $removed->id))->toMatchArray(['status' => 'removed', 'author_name' => null, 'author_email' => null, 'body' => null])
        ->and(($this->line)($lines, $reply->id)['parent_id'])->toBe($removed->id);
});

it('reads every comment and every person exactly once, a page at a time', function (): void {
    $item = cmItem($this->editor);
    $comments = [];

    foreach (range(1, 5) as $n) {
        $comments[] = cmComment($item)->id;
        User::factory()->create();
    }

    $people = DB::table('users')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

    expect(array_column(ExportAs::kindLines(app(CommentsKind::class), pageSize: 2), 'id'))->toEqualCanonicalizing($comments)
        ->and(count(array_unique(array_column(ExportAs::kindLines(app(CommentsKind::class), pageSize: 2), 'id'))))->toBe(5)
        ->and(array_column(ExportAs::kindLines(app(PeopleKind::class), pageSize: 2), 'id'))->toBe($people);
});
