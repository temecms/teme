<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Cache\Footprints;
use App\Domain\Contents\ContentState;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\Visibility;
use App\Domain\Taxonomy\TermProjection;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->item = cmItem($this->editor, 'Harbour news');
    // Straight to the table: the Operation that does this is the next task's.
    $this->setVisibility = function (Content $item, string $visibility, ?string $sealed = null): Content {
        DB::table('contents')->where('id', $item->id)->update(['visibility' => $visibility, 'reader_password' => $sealed]);

        return Content::query()->findOrFail($item->id);
    };
});

it('gives every item a visibility, Public by default, and refuses one it doesn\'t know (I-1700)', function (): void {
    expect(DB::table('contents')->where('id', $this->item->id)->value('visibility'))->toBe('public')
        ->and(Content::query()->findOrFail($this->item->id)->visibility)->toBe(Visibility::Public)
        ->and((new Content)->visibility)->toBe(Visibility::Public)
        ->and(fn () => DB::table('contents')->where('id', $this->item->id)->update(['visibility' => 'secret']))->toThrow(QueryException::class);
});

it('keeps a reader password on a protected item only, and never a protected item without one (I-1700)', function (string $case): void {
    $change = match ($case) {
        'protected without a password' => ['visibility' => 'protected', 'reader_password' => null],
        'public with a password' => ['visibility' => 'public', 'reader_password' => ReaderPasswords::seal('harbour')],
        'private with a password' => ['visibility' => 'private', 'reader_password' => ReaderPasswords::seal('harbour')],
    };

    expect(fn () => DB::table('contents')->where('id', $this->item->id)->update($change))->toThrow(QueryException::class);
})->with(['protected without a password', 'public with a password', 'private with a password']);

it('counts a published private item in a bucket of its own, and the counts\' rebuild agrees (I-1700)', function (): void {
    $private = ($this->setVisibility)($this->item, 'private');

    expect(ContentState::of($private)->bucket)->toBe('private');

    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(DB::table('content_counts')->where(['type' => 'post', 'bucket' => 'private', 'author_id' => 0])->value('count'))->toBe(1)
        ->and((int) DB::table('content_counts')->where(['type' => 'post', 'bucket' => 'published', 'author_id' => 0])->value('count'))->toBe(0)
        ->and(ContentState::of(($this->setVisibility)(lifecycleDraft($this->editor, 'Notes'), 'private'))->bucket)->toBe('draft');
});

it('gives a private item no address, files nothing of it where readers look, and no footprint (I-1700)', function (): void {
    $folder = cacheOn();
    $news = taxonomyTerm('category', 'News');
    $item = Visibilities::published($this->editor, 'Staff notes', 'zq', [$news->id]);
    $private = ($this->setVisibility)($item, 'private');

    TermProjection::sync($private);
    app(PathProjection::class)->syncContent($private);

    // A public item's footprint first, so an empty one below means something.
    expect(Footprints::contents([$this->item])[$this->item->id])->toContain('content:'.$this->item->id)
        ->and(addrCurrent('content', $private->id))->toBeNull()
        ->and(addrGet('/staff-notes/')->getStatusCode())->toBe(404)
        ->and(DB::table('content_terms')->where(['content_id' => $private->id, 'scope' => 'published'])->count())->toBe(0)
        ->and(DB::table('content_terms')->where(['content_id' => $private->id, 'scope' => 'working'])->count())->toBe(1)
        ->and(Footprints::contents([$private])[$private->id])->toBe([]);

    cacheOff($folder);
});

it('never puts the reader password in the model\'s array or JSON (I-1700)', function (): void {
    $protected = ($this->setVisibility)($this->item, 'protected', ReaderPasswords::seal('harbour-secret'));

    expect($protected->toArray())->not->toHaveKey('reader_password')
        ->and($protected->toJson())->not->toContain('reader_password')
        ->and(DB::table('contents')->where('id', $protected->id)->value('reader_password'))->not->toContain('harbour-secret');
});
