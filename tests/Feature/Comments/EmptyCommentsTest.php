<?php

declare(strict_types=1);

use App\Domain\Comments\Jobs\EmptyComments;
use App\Domain\Comments\Models\Comment;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Http\Admin\Comments\CommentEmptyController;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
    $this->removed = ['author_name' => null, 'author_email' => null, 'body' => null];
});

it('empties what the list shows, on every page, as it was when opened: spam that arrived since stays (C4, D4, D7)', function (): void {
    $before = array_map(fn (int $n): string => cmNext($n % 2 === 0 ? $this->hers : $this->his, 'spam')->id, range(1, 26));
    $opened = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    $since = cmNext($this->his, 'spam');

    cmEmpty($this->editor, ['openedAt' => $opened])
        ->assertRedirect(route('comments.index', ['view' => 'spam']))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Emptied spam: deleted 26 comments permanently.');

    expect(Comment::query()->whereKey($before)->exists())->toBeFalse()
        ->and($since->refresh()->status)->toBe('spam');
});

it('empties only the item the list is narrowed to, and only what its search finds (C4, D4)', function (): void {
    $his = cmNext($this->his, 'spam');
    $casino = cmNext($this->hers, 'spam', attributes: ['body' => cmBody('Cheap casino chips.')]);
    $plain = cmNext($this->hers, 'spam');

    cmEmpty($this->editor, ['item' => $this->his->id])
        ->assertRedirect(route('comments.index', ['view' => 'spam', 'item' => $this->his->id]))
        ->assertInertiaFlash('toast.message', 'Emptied spam: deleted 1 comment permanently.');

    expect(Comment::query()->whereKey($his->id)->exists())->toBeFalse()
        ->and(Comment::query()->whereKey([$casino->id, $plain->id])->count())->toBe(2);

    cmEmpty($this->editor, ['search' => 'CASINO'])->assertInertiaFlash('toast.message', 'Emptied spam: deleted 1 comment permanently.');

    expect(Comment::query()->whereKey($casino->id)->exists())->toBeFalse()
        ->and($plain->refresh()->status)->toBe('spam');
});

it('lets an author empty spam on their own items, and only there (C4, I-483, I-570)', function (): void {
    $hers = cmNext($this->hers, 'spam');
    $his = cmNext($this->his, 'spam');

    cmEmpty($this->author)->assertInertiaFlash('toast.message', 'Emptied spam: deleted 1 comment permanently.');

    expect(Comment::query()->whereKey($hers->id)->exists())->toBeFalse()
        ->and($his->refresh()->status)->toBe('spam');
});

it('empties the trash deepest first, so a comment whose replies all go goes too, and counts the tombstones (C4, D4, I-577)', function (): void {
    $top = cmNext($this->hers, 'trash');
    $reply = cmNext($this->hers, 'trash', $top);
    $answered = cmNext($this->hers, 'trash');
    $kept = cmNext($this->hers, 'approved', $answered);

    cmEmpty($this->editor, ['view' => 'trash'])
        ->assertRedirect(route('comments.index', ['view' => 'trash']))
        ->assertInertiaFlash('toast.message', 'Emptied the trash: deleted 3 comments permanently. 1 stays as “This comment was removed”, so its replies keep their place.');

    expect(Comment::query()->whereKey([$top->id, $reply->id])->exists())->toBeFalse()
        ->and($answered->refresh()->status)->toBe('removed')
        ->and($kept->refresh()->status)->toBe('approved');
});

it('empties Spam and the trash alone, and refuses what isn\'t a list it can empty (C4, D4)', function (array $fields, string $field): void {
    $kept = cmNext($this->hers);

    cmEmpty($this->editor, $fields)->assertSessionHasErrors($field);

    expect($kept->refresh()->status)->toBe('approved');
})->with([
    'the Approved view' => [['view' => 'approved'], 'view'],
    'no moment it was opened' => [['openedAt' => ''], 'openedAt'],
    'an item that isn\'t an ID' => [['item' => 'nope'], 'item'],
]);

it('writes nothing for someone who looks after no comments, nor while comments are switched off (authorization)', function (): void {
    $spam = cmNext($this->hers, 'spam');

    cmEmpty(User::factory()->create()->assignRole('contributor'))->assertForbidden();
    cmSettings(['comments' => 'off'], 'modules');
    cmEmpty($this->editor)->assertNotFound();

    expect($spam->refresh()->status)->toBe('spam');
});

it('says so when nothing could be emptied, because it all changed since the list was opened (C4, I-682)', function (): void {
    $opened = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    $later = cmNext($this->hers, 'spam');

    cmEmpty($this->editor, ['openedAt' => $opened])
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Nothing was deleted, because the comments had changed since you opened the list.');

    expect($later->refresh()->status)->toBe('spam');
});

it('gives Spam and the trash what their confirmation says: how many, how many keep a place and their replies (C4, D6, B-367)', function (): void {
    $answered = cmComment($this->hers, 'trash');
    cmComment($this->hers, 'approved', $answered);
    cmComment($this->hers, 'approved', $answered);
    $top = cmComment($this->hers, 'trash');
    cmComment($this->hers, 'trash', $top);
    $holder = cmComment($this->hers, 'trash');
    $stone = cmComment($this->hers, 'removed', $holder, $this->removed);
    cmComment($this->hers, 'trash', $stone);

    cmScreen($this->editor, ['view' => 'trash'])->assertInertia(fn (Assert $page) => $page
        ->where('emptying', ['count' => 5, 'tombstones' => 1, 'replies' => 2])
        ->where('openedAt', now()->utc()->format('Y-m-d\TH:i:s.u\Z'))
        ->where('trashDays', 30));

    cmScreen($this->editor, ['view' => 'trash', 'search' => 'nothing says this'])->assertInertia(fn (Assert $page) => $page
        ->where('emptying', ['count' => 0, 'tombstones' => 0, 'replies' => 0]));

    cmScreen($this->editor)->assertInertia(fn (Assert $page) => $page->where('emptying', null));
});

it('empties the first part at once and hands the rest to the queue, 2,000 a job, as the person who asked, with the same cut-off (I-884)', function (): void {
    Queue::fake();
    app()->when(CommentEmptyController::class)->needs('$inRequest')->give(2);
    array_map(fn (int $n): Comment => cmNext($this->hers, 'spam'), range(1, 3));
    $opened = now()->utc()->format('Y-m-d\TH:i:s.u\Z');

    cmEmpty($this->author, ['openedAt' => $opened])
        ->assertInertiaFlash('toast.type', 'info')
        ->assertInertiaFlash('toast.message', 'Emptying spam: deleted 2 comments permanently. Teme is deleting the other 1 now.');

    Queue::assertPushed(EmptyComments::class, fn (EmptyComments $job): bool => [$job->viewerId, $job->view, $job->itemId, $job->search, $job->cutoff, $job->limit] === [(int) $this->author->id, 'spam', null, null, $opened, 2000]);

    expect(Comment::query()->where('status', 'spam')->count())->toBe(1);
});

it('runs on the queue as the person who asked, with no elevation, and deletes nothing for someone who looks after none (D5, B-098)', function (): void {
    $hers = cmNext($this->hers, 'spam');
    $his = cmNext($this->his, 'spam');
    $cutoff = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    $this->app->instance(Elevation::class, new DeniedElevation);

    app()->call([new EmptyComments((int) User::factory()->create()->assignRole('contributor')->id, 'spam', null, null, $cutoff), 'handle']);

    expect(Comment::query()->whereKey([$hers->id, $his->id])->count())->toBe(2);

    app()->call([new EmptyComments((int) $this->author->id, 'spam', null, null, $cutoff), 'handle']);

    expect(Comment::query()->whereKey($hers->id)->exists())->toBeFalse()
        ->and($his->refresh()->status)->toBe('spam');
});

it('hands what a job leaves to the next, with the same person and cut-off, and nothing on when it had nothing to delete (D5)', function (): void {
    Queue::fake();
    cmNext($this->hers, 'spam');
    cmNext($this->hers, 'spam');
    $cutoff = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    $job = fn (int $viewerId): EmptyComments => new EmptyComments($viewerId, 'spam', $this->hers->id, null, $cutoff, limit: 1);

    app()->call([$job((int) $this->author->id), 'handle']);

    Queue::assertPushed(EmptyComments::class, fn (EmptyComments $next): bool => [$next->viewerId, $next->itemId, $next->cutoff, $next->limit] === [(int) $this->author->id, $this->hers->id, $cutoff, 1]);

    expect(Comment::query()->where('status', 'spam')->count())->toBe(1);

    app()->call([$job((int) User::factory()->create()->assignRole('contributor')->id), 'handle']);

    Queue::assertPushedTimes(EmptyComments::class, 1);
});
