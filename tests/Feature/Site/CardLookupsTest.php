<?php

declare(strict_types=1);

use App\Domain\Cards\CardMedia;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Issue 30: a listing page reads its authors' addresses and its cards'
 * images once each, however many cards it shows, so its statements are
 * fixed by the page, never by its number of cards, authors or images.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('reads an archive\'s authors\' addresses and card images once each, however many cards it shows (issue 30)', function (): void {
    $one = cardsCategory('One');
    $six = cardsCategory('Six');
    cardsPost($one, 'Alone');

    foreach (range(1, 6) as $n) {
        cardsPost($six, "Story {$n}");
    }

    expect(cardsStatements('/category/six/'))->toBe(cardsStatements('/category/one/'));
});

it('reads the front page\'s lead, pinned posts and feed as one list, whatever their number (issue 30)', function (): void {
    $news = cardsCategory('News');
    cardsPost($news, 'First pin', sticky: true);
    $alone = cardsStatements('/');

    foreach (range(1, 3) as $n) {
        cardsPost($news, "Pin {$n}", sticky: true);
    }

    foreach (range(1, 6) as $n) {
        cardsPost($news, "Story {$n}");
    }

    expect(cardsStatements('/'))->toBe($alone);
});

it('reads each image once in a request, a trashed one too, and afresh in the next (issue 30)', function (): void {
    $harbour = pageImage($this->editor, 1200, 800);
    $trashed = pageImage($this->editor, 800, 600);
    app(TrashMedia::class)->execute(new MediaActionInput($trashed->id, (string) $this->editor->id));

    $first = dashQueries(fn () => app(CardMedia::class)->forIds([$harbour->id, $trashed->id]));
    $again = dashQueries(fn () => app(CardMedia::class)->forIds([$trashed->id, $harbour->id]));
    app()->forgetScopedInstances();
    $next = dashQueries(fn () => app(CardMedia::class)->forIds([$harbour->id]));

    expect($first)->toHaveCount(1)
        ->and($again)->toBe([])
        ->and($next)->toHaveCount(1)
        ->and(array_keys(app(CardMedia::class)->forIds([$harbour->id, $trashed->id])))->toBe([$harbour->id]);
});
