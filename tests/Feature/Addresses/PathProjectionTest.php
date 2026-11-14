<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
});

it('gives a published post its address', function (): void {
    $post = addrPublished($this->editor, 'Hello World');

    expect(addrCurrent('content', $post->id))->toBe('hello-world');
});

it('gives an unpublished draft no address', function (): void {
    $draft = lifecycleDraft($this->editor, 'Not yet');

    expect(addrCurrent('content', $draft->id))->toBeNull();
});

it('keeps the old address as history when the slug changes', function (): void {
    $post = addrPublished($this->editor, 'Hello World');

    addrRename($post, 'hello-again', $this->editor);

    expect(addrCurrent('content', $post->id))->toBe('hello-again')
        ->and(DB::table('paths')->where('path', 'hello-world')->value('is_current'))->toBeFalse();
});

it('refuses a slug whose address another live item holds, naming it', function (): void {
    addrPublished($this->editor, 'About', 'page');
    $post = addrPublished($this->editor, 'Something else');

    expect(fn () => addrRename($post, 'about', $this->editor))
        ->toThrow(ValidationException::class, 'About');
});

it('lets a live item take over another item\'s old address', function (): void {
    $first = addrPublished($this->editor, 'Launch');
    addrRename($first, 'launch-day', $this->editor);

    $second = addrPublished($this->editor, 'Launch');

    expect(addrCurrent('content', $second->id))->toBe('launch');
});

it('turns a trashed item\'s address into history', function (): void {
    $post = addrPublished($this->editor, 'Gone soon');

    app(TrashContent::class)->execute(new ContentActionInput($post->id, (string) $this->editor->id));

    expect(addrCurrent('content', $post->id))->toBeNull()
        ->and(DB::table('paths')->where('path', 'gone-soon')->exists())->toBeTrue();
});

it('gives categories and authors their addresses, nested categories with their parents', function (): void {
    $news = taxonomyTerm('category', 'News');
    $local = taxonomyTerm('category', 'Local', $news->id);
    app(PathProjection::class)->syncTerm($news);
    app(PathProjection::class)->syncTerm($local);
    app(PathProjection::class)->syncUser($this->editor);

    expect(addrCurrent('term', $local->id))->toBe('category/news/local')
        ->and(addrCurrent('user', (string) $this->editor->id))->toBe('author/jane-doe');
});

it('writes nothing when the person may not change the item', function (): void {
    $post = addrPublished($this->editor, 'Mine');
    $stranger = User::factory()->create()->assignRole('contributor');

    expect(fn () => addrRename($post, 'theirs', $stranger))
        ->toThrow(AuthorizationException::class);

    expect(addrCurrent('content', $post->id))->toBe('mine')
        ->and(DB::table('paths')->where('path', 'theirs')->exists())->toBeFalse();
});
