<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\Visibility;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->contributor = User::factory()->create(['name' => 'Chidi Contributor'])->assignRole('contributor');
    $this->item = cmItem($this->author, 'Harbour news');
    $this->folder = cacheOn();
    $this->row = fn (Content $item): array => (array) DB::table('contents')->where('id', $item->id)->first(['visibility', 'reader_password', 'status', 'updated_at', 'working_revision_id', 'published_revision_id']);
    $this->count = fn (string $bucket): int => (int) DB::table('content_counts')->where(['type' => 'post', 'bucket' => $bucket, 'author_id' => 0])->value('count');
    $this->refusal = function (Closure $change): ?string {
        try {
            $change();
        } catch (ValidationException $refused) {
            return collect($refused->errors())->flatten()->first();
        }

        return null;
    };
});

afterEach(fn () => cacheOff($this->folder));

it('makes a published item private: its address answers “not found”, it counts as Private, and the pages that showed it go (I-1700)', function (): void {
    cacheShown(['/harbour-news/' => ['content:'.$this->item->id]]);

    $private = Visibilities::change($this->item, 'private', $this->author);
    // Before the request below: the cache stores that request's “not found” at the same address.
    $kept = cacheKept('/harbour-news/');

    expect($private->visibility)->toBe(Visibility::Private)
        ->and(addrCurrent('content', $private->id))->toBeNull()
        ->and(addrGet('/harbour-news/')->getStatusCode())->toBe(404)
        ->and(($this->count)('private'))->toBe(1)
        ->and(($this->count)('published'))->toBe(0)
        ->and($kept)->toBe([]);
});

it('makes a private item public again at the address it had (I-1700)', function (): void {
    Visibilities::change($this->item, 'private', $this->author);
    $public = Visibilities::change($this->item, 'public', $this->author);

    expect($public->visibility)->toBe(Visibility::Public)
        ->and(addrCurrent('content', $public->id))->toBe('harbour-news')
        ->and(addrGet('/harbour-news/')->getStatusCode())->toBe(200)
        ->and(($this->count)('private'))->toBe(0)
        ->and(($this->count)('published'))->toBe(1);
});

it('keeps a private item private while another holds the address it would come back to, writing nothing (I-1700)', function (string $to): void {
    Visibilities::change($this->item, 'private', $this->author);
    $taker = cmItem($this->editor, 'Harbour news', 'page');
    $before = ($this->row)($this->item);

    expect(($this->refusal)(fn () => Visibilities::change($this->item, $to, $this->author, 'Harbour 2024')))->toContain('/harbour-news/')
        ->and(($this->row)($this->item))->toBe($before)
        ->and(addrCurrent('content', $taker->id))->toBe('harbour-news');
})->with(['public', 'protected']);

it('protects an item with a password it keeps sealed, and changes the password alone when asked (I-1700)', function (): void {
    $protected = Visibilities::change($this->item, 'protected', $this->author, 'Harbour 2024');
    $sealed = DB::table('contents')->where('id', $this->item->id)->value('reader_password');
    $changed = Visibilities::change($this->item, 'protected', $this->author, 'Harbour 2025');

    expect($protected->visibility)->toBe(Visibility::Protected)
        ->and($sealed)->not->toContain('Harbour')
        ->and(ReaderPasswords::open($protected))->toBe('Harbour 2024')
        ->and(ReaderPasswords::open($changed))->toBe('Harbour 2025')
        // Still at its address, still counted as published: readers find it, then the form.
        ->and(addrCurrent('content', $changed->id))->toBe('harbour-news')
        ->and(($this->count)('published'))->toBe(1);
});

it('refuses a password that is missing, only spaces or longer than 255 characters, writing nothing (I-1700)', function (?string $password): void {
    $before = ($this->row)($this->item);

    expect(($this->refusal)(fn () => Visibilities::change($this->item, 'protected', $this->author, $password)))->toBe('Enter a password of up to 255 characters.')
        ->and(($this->row)($this->item))->toBe($before);
})->with(['missing' => [null], 'empty' => [''], 'spaces only' => ['   '], '256 characters' => [str_repeat('h', 256)]]);

it('forgets the password when the item goes public or private (I-1700)', function (string $to): void {
    Visibilities::change($this->item, 'protected', $this->author, 'Harbour 2024');
    Visibilities::change($this->item, $to, $this->author, 'ignored');

    expect(DB::table('contents')->where('id', $this->item->id)->value('reader_password'))->toBeNull();
})->with(['public', 'private']);

it('keeps the home page and the posts page public (I-1700)', function (string $which, string $to): void {
    $home = cmItem($this->editor, 'Welcome', 'page');
    $posts = cmItem($this->editor, 'News', 'page');
    addrChange($this->admin, ['frontShows' => 'page', 'frontPageId' => $home->id, 'postsPageId' => $posts->id]);
    $page = $which === 'home' ? $home : $posts;
    $before = ($this->row)($page);

    expect(($this->refusal)(fn () => Visibilities::change($page, $to, $this->editor, 'Harbour 2024')))
        ->toBe($which === 'home' ? 'This is your home page. Choose another in Settings › Reading first.' : 'This is your posts page. Choose another in Settings › Reading first.')
        ->and(($this->row)($page))->toBe($before);
})->with([['home', 'private'], ['home', 'protected'], ['posts', 'private'], ['posts', 'protected']]);

it('won\'t make a page that isn\'t public the home page (I-1700)', function (): void {
    $page = Visibilities::change(cmItem($this->editor, 'Welcome', 'page'), 'protected', $this->editor, 'Harbour 2024');

    expect(fn () => addrChange($this->admin, ['frontShows' => 'page', 'frontPageId' => $page->id]))->toThrow(ValidationException::class);
});

it('sets a draft\'s visibility for when it is published, and a private one published has no address (I-1700)', function (): void {
    $draft = Visibilities::change(lifecycleDraft($this->author, 'Staff notes'), 'private', $this->author);

    expect(($this->count)('draft'))->toBe(1);

    listsPublish($draft, $this->author);

    expect(Content::query()->findOrFail($draft->id)->status)->toBe('published')
        ->and(addrCurrent('content', $draft->id))->toBeNull()
        ->and(($this->count)('private'))->toBe(1);
});

it('changes the visibility alone: not the revisions, the status, or when the item was last updated (I-1700)', function (): void {
    $before = ($this->row)($this->item);
    $revisions = DB::table('content_revisions')->where('content_id', $this->item->id)->count();
    $this->travel(5)->minutes();

    Visibilities::change($this->item, 'private', $this->author);

    expect(($this->row)($this->item))->toBe([...$before, 'visibility' => 'private'])
        ->and(DB::table('content_revisions')->where('content_id', $this->item->id)->count())->toBe($revisions);
});

it('lets whoever may publish the item change it: its author, or an editor on anyone\'s (I-1700)', function (string $who): void {
    $item = Visibilities::change($this->item, 'private', $who === 'its author' ? $this->author : $this->editor);

    expect($item->visibility)->toBe(Visibility::Private);
})->with(['its author', 'an editor']);

it('writes nothing when authorization fails: a contributor on their own draft, an author on someone else\'s post (I-1700)', function (string $case): void {
    [$item, $person] = $case === 'a contributor on their own draft'
        ? [lifecycleDraft($this->contributor, 'Notes'), $this->contributor]
        : [cmItem($this->editor, 'Market day'), $this->author];
    cacheShown(['/shows-it/' => ['content:'.$item->id]]);
    $before = ($this->row)($item);

    expect(fn () => Visibilities::change($item, 'protected', $person, 'Harbour 2024'))->toThrow(AuthorizationException::class, 'Not permitted to change this item’s visibility.')
        ->and(($this->row)($item))->toBe($before)
        ->and(cacheKept('/shows-it/'))->toBe(['/shows-it/']);
})->with(['a contributor on their own draft', 'an author on someone else\'s post']);

it('refuses a value it doesn\'t know, and an item of a type with no page of its own (I-1700)', function (): void {
    $region = DB::table('contents')->insertGetId(['id' => strtolower((string) Str::ulid()), 'type' => 'region', 'slug' => 'default--footer', 'status' => 'draft', 'author_id' => $this->admin->id, 'fields' => '{}', 'created_at' => now(), 'updated_at' => now()], 'id');

    expect(($this->refusal)(fn () => Visibilities::change($this->item, 'secret', $this->author)))->toBe('Choose Public, Private or Password protected.')
        ->and(($this->refusal)(fn () => Visibilities::change(Content::query()->findOrFail($region), 'private', $this->admin)))->toBe('Items of this type have no page of their own, so they have no visibility.');
});

it('answers false and purges nothing when nothing changes (I-1700)', function (): void {
    cacheShown(['/harbour-news/' => ['content:'.$this->item->id]]);

    expect(app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($this->item->id, 'public', null, (string) $this->author->id)))->toBeFalse()
        ->and(cacheKept('/harbour-news/'))->toBe(['/harbour-news/']);
});

it('keeps every count exact: the counts\' rebuild finds nothing to fix after items go private and back (I-1700)', function (): void {
    $other = cmItem($this->author, 'Market day');
    Visibilities::change($this->item, 'private', $this->author);
    Visibilities::change($other, 'private', $this->author);
    Visibilities::change($other, 'public', $this->author);
    Visibilities::change(lifecycleDraft($this->author, 'Notes'), 'protected', $this->author, 'Harbour 2024');

    $this->artisan('teme:counts:rebuild', ['--check' => true])
        ->expectsOutput('No drift: every count matches the content.')
        ->assertSuccessful();
});
