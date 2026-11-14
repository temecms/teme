<?php

declare(strict_types=1);

use App\Domain\Comments\Queries\CommentsWaiting;
use App\Domain\Dashboard\Queries\SiteFigures;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
    $this->tides = cmItem($this->editor, 'Tide tables');
    cmComment($this->hers, 'pending');
    cmComment($this->his, 'pending');
    cmComment($this->his, 'pending');
    cmComment($this->tides, 'pending');
    cmComment($this->tides, 'spam');
    $this->figure = fn (User $viewer): ?array => collect(app(SiteFigures::class)->forUser($viewer))->firstWhere('key', 'comments.waiting');
});

it('counts the comments waiting on the items a person looks after, spam and the trash left out (D19, C10)', function (): void {
    $waiting = app(CommentsWaiting::class);

    expect($waiting->countFor($this->editor))->toBe(4)
        ->and($waiting->countFor($this->author))->toBe(1)
        ->and($waiting->countFor(User::factory()->create()->assignRole('contributor')))->toBe(0);

    pageTrash($this->his, $this->editor);

    expect($waiting->countFor($this->editor))->toBe(2);
});

it('gives Home the figure in each person\'s words, linked to the Pending view (C2, D20)', function (): void {
    expect(($this->figure)($this->editor))->toBe(['key' => 'comments.waiting', 'label' => 'Comments waiting', 'group' => null, 'value' => 4, 'url' => route('comments.index', ['view' => 'pending'])])
        ->and(($this->figure)($this->author))->toMatchArray(['label' => 'Comments waiting on your posts and pages', 'value' => 1])
        ->and(array_column(app(SiteFigures::class)->forUser($this->editor), 'key'))->toContain('page.scheduled', 'comments.waiting', 'media');
});

it('gives Home no comments figure for someone who looks after none, nor while comments are off (D20)', function (): void {
    expect(($this->figure)(User::factory()->create()->assignRole('contributor')))->toBeNull();

    cmSettings(['comments' => 'off'], 'modules');

    expect(($this->figure)($this->editor))->toBeNull()
        ->and(app(CommentsWaiting::class)->countFor($this->editor))->toBe(0);
});
