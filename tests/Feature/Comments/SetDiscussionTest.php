<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Comments\Operations\Input\SetDiscussionInput;
use App\Domain\Comments\Operations\SetDiscussion;
use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->contributor = User::factory()->create(['name' => 'Chidi Contributor'])->assignRole('contributor');
    // A published post, which starts Open (CoreTypes).
    $this->item = cmItem($this->author, 'Harbour news');
    $this->folder = cacheOn();
    $this->set = fn (Content $item, string $to, User $by): bool => app(SetDiscussion::class)->execute(new SetDiscussionInput($item->id, $to, (string) $by->id));
    $this->row = fn (Content $item): array => (array) DB::table('contents')->where('id', $item->id)->first(['comment_status', 'updated_at', 'working_revision_id', 'published_revision_id', 'status']);
});

afterEach(fn () => cacheOff($this->folder));

it('changes an item\'s Discussion alone: not its revisions, its status, or when it was last updated (I-571)', function (): void {
    $before = ($this->row)($this->item);
    $revisions = DB::table('content_revisions')->where('content_id', $this->item->id)->count();
    $this->travel(5)->minutes();

    expect(($this->set)($this->item, 'closed', $this->editor))->toBeTrue()
        ->and(($this->row)($this->item))->toBe([...$before, 'comment_status' => 'closed'])
        ->and(DB::table('content_revisions')->where('content_id', $this->item->id)->count())->toBe($revisions);
});

it('lets whoever may publish the item set it: its author, or an editor on anyone\'s (I-571)', function (string $who): void {
    ($this->set)($this->item, 'hidden', $who === 'its author' ? $this->author : $this->editor);

    expect(Content::query()->findOrFail($this->item->id)->comment_status)->toBe(Discussion::Hidden);
})->with(['its author', 'an editor']);

it('writes nothing when authorization fails: a contributor on their own draft, an author on someone else\'s post (I-571)', function (string $case): void {
    [$item, $person] = $case === 'a contributor on their own draft'
        ? [lifecycleDraft($this->contributor, 'Notes'), $this->contributor]
        : [cmItem($this->editor, 'Market day'), $this->author];
    cacheShown(['/shows-it/' => ['content:'.$item->id]]);
    $before = ($this->row)($item);

    expect(fn () => ($this->set)($item, 'hidden', $person))->toThrow(AuthorizationException::class)
        ->and(($this->row)($item))->toBe($before)
        ->and(cacheKept('/shows-it/'))->toBe(['/shows-it/']);
})->with(['a contributor on their own draft', 'an author on someone else\'s post']);

it('removes the published item\'s pages after the change commits, none after a rollback, and tells nothing for a draft', function (): void {
    $draft = lifecycleDraft($this->author, 'Not yet');
    cacheShown([
        '/harbour-news/' => ['content:'.$this->item->id],
        '/harbour-news/comments/page/2/' => ['content:'.$this->item->id, 'comments:'.$this->item->id],
        '/not-yet/' => ['content:'.$draft->id],
        '/news/' => ['list:post'],
    ]);
    $pages = ['/harbour-news/', '/harbour-news/comments/page/2/', '/not-yet/', '/news/'];

    cacheRolledBack(fn () => ($this->set)($this->item, 'closed', $this->editor));

    expect(cacheKept(...$pages))->toBe($pages);

    ($this->set)($this->item, 'closed', $this->editor);
    ($this->set)($draft, 'closed', $this->author);

    expect(cacheKept(...$pages))->toBe(['/not-yet/', '/news/']);
});

it('writes nothing and removes no page when the item already has that Discussion', function (): void {
    cacheShown(['/harbour-news/' => ['content:'.$this->item->id]]);

    $statements = dashQueries(function (): void {
        expect(($this->set)($this->item, 'open', $this->editor))->toBeFalse();
    });

    expect(array_values(array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(update|insert|delete)\b/i', $sql) === 1)))->toBe([])
        ->and(cacheKept('/harbour-news/'))->toBe(['/harbour-news/']);
});

it('refuses, and writes nothing, while comments are off, for a type that takes none, and for a state that isn\'t one', function (string $case, string $message): void {
    match ($case) {
        'comments off' => cmSettings(['comments' => 'off'], 'modules'),
        'a type that takes none' => DB::table('contents')->where('id', $this->item->id)->update(['type' => 'region']),
        default => null,
    };
    // An administrator holds regions.manage, a region's publish ability, so validation decides.
    $admin = User::factory()->create()->assignRole('administrator');
    $before = ($this->row)($this->item);

    expect(fn () => ($this->set)($this->item, $case === 'a state that isn\'t one' ? 'locked' : 'closed', $admin))->toThrow(ValidationException::class, $message)
        ->and(($this->row)($this->item))->toBe($before);
})->with([
    'comments off' => ['comments off', 'Comments are switched off.'],
    'a type that takes none' => ['a type that takes none', 'Regions take no comments.'],
    'a state that isn\'t one' => ['a state that isn\'t one', 'Choose Open, Closed or Hidden.'],
]);

it('finds no item in the trash, and writes nothing', function (): void {
    pageTrash($this->item, $this->author);

    expect(fn () => ($this->set)($this->item, 'closed', $this->editor))->toThrow(ModelNotFoundException::class)
        ->and(DB::table('contents')->where('id', $this->item->id)->value('comment_status'))->toBe('open');
});
