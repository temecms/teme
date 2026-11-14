<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\InviteUser;
use App\Domain\Users\Operations\UpdateProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->withElevation();
    $this->editor = User::factory()->create(['name' => 'Ada Obi'])->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('purges a "not found" page at the address a new term takes', function (): void {
    cachePage('/category/ferries/');
    cachePage('/category/tides/');

    cacheRolledBack(fn () => typesTerm($this->editor, 'category', 'Ferries'));

    expect(cacheKept('/category/ferries/', '/category/tides/'))->toHaveCount(2);

    typesTerm($this->editor, 'category', 'Ferries');

    expect(cacheKept('/category/ferries/', '/category/tides/'))->toBe(['/category/tides/']);
});

it('purges the pages that show a term, list what is filed under it, or stand at its old or new address, when it is renamed', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    cacheShown(['/shows-its-name/' => ['term:'.$news->id], '/lists-its-posts/' => ['term:category:'.$news->id], '/shows-another/' => ['term:unrelated']]);
    cachePage('/category/news/');
    cachePage('/category/press/');
    $pages = ['/shows-its-name/', '/lists-its-posts/', '/category/news/', '/category/press/', '/shows-another/'];
    $rename = fn () => app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Press', 'press', null, null, (string) $this->editor->id, termId: $news->id));

    cacheRolledBack($rename);

    expect(cacheKept(...$pages))->toHaveCount(5);

    $rename();

    expect(cacheKept(...$pages))->toBe(['/shows-another/']);
});

it('purges each term beneath a renamed one, whose addresses hold its slug', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $local = typesTerm($this->editor, 'category', 'Local', $news->id);
    cacheShown(['/shows-local/' => ['term:'.$local->id], '/lists-local/' => ['term:category:'.$local->id]]);
    cachePage('/category/news/local/');
    cachePage('/category/press/local/');

    app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Press', 'press', null, null, (string) $this->editor->id, termId: $news->id));

    expect(cacheKept('/shows-local/', '/lists-local/', '/category/news/local/', '/category/press/local/'))->toBe([]);
});

it('purges the pages that show a deleted term or list what was filed under it, and its children\'s, which move up', function (): void {
    $news = typesTerm($this->editor, 'category', 'News');
    $local = typesTerm($this->editor, 'category', 'Local', $news->id);
    cacheShown(['/shows-news/' => ['term:'.$news->id], '/lists-news/' => ['term:category:'.$news->id], '/shows-local/' => ['term:'.$local->id], '/shows-another/' => ['term:unrelated']]);
    cachePage('/category/local/');
    $pages = ['/shows-news/', '/lists-news/', '/shows-local/', '/category/local/', '/shows-another/'];
    $delete = fn () => app(DeleteTerm::class)->execute(new DeleteTermInput($news->id, (string) $this->editor->id));

    cacheRolledBack($delete);

    expect(cacheKept(...$pages))->toHaveCount(5);

    $delete();

    expect(cacheKept(...$pages))->toBe(['/shows-another/']);
});

it('purges the pages that show a person, and their archive, when their name or bio changes', function (): void {
    $writer = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    cacheShown(['/shows-jane/' => ['person:'.$writer->id], '/lists-jane/' => ['author:'.$writer->id], '/shows-someone-else/' => ['person:999']]);
    $rename = fn () => app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $writer->id, 'Jane Obi', $writer->email, (string) $writer->id));

    cacheRolledBack($rename);

    expect(cacheKept('/shows-jane/', '/lists-jane/', '/shows-someone-else/'))->toHaveCount(3);

    $rename();

    expect(cacheKept('/shows-jane/', '/lists-jane/', '/shows-someone-else/'))->toBe(['/shows-someone-else/']);
});

it('purges nothing when only a person\'s email changes, which no page shows', function (): void {
    $writer = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    cacheShown(['/shows-jane/' => ['person:'.$writer->id]]);

    app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $writer->id, 'Jane Doe', 'jane.new@example.com', (string) $writer->id));

    expect(cacheKept('/shows-jane/'))->toBe(['/shows-jane/']);
});

it('purges the pages that show a deleted person, their archive, and the archive of whoever receives their work', function (): void {
    $writer = User::factory()->create()->assignRole('author');
    lifecycleDraft($writer, 'Their draft');
    cacheShown([
        '/shows-them/' => ['person:'.$writer->id],
        '/lists-theirs/' => ['author:'.$writer->id],
        '/lists-ada/' => ['author:'.$this->editor->id],
        '/lists-someone-else/' => ['author:999'],
    ]);
    $pages = ['/shows-them/', '/lists-theirs/', '/lists-ada/', '/lists-someone-else/'];
    $delete = fn () => app(DeleteUser::class)->execute(new DeleteUserInput((string) $writer->id, (string) $this->editor->id, (string) $this->admin->id));

    cacheRolledBack($delete);

    expect(cacheKept(...$pages))->toHaveCount(4);

    $delete();

    expect(cacheKept(...$pages))->toBe(['/lists-someone-else/']);
});

it('purges a "not found" page at the author address a new person takes', function (): void {
    cachePage('/author/new-person/');
    cachePage('/author/someone/');
    $invite = fn () => app(InviteUser::class)->execute(new InviteUserInput('New Person', 'new@example.com', 'author', (string) $this->admin->id, deliver: false));

    cacheRolledBack($invite);

    expect(cacheKept('/author/new-person/', '/author/someone/'))->toHaveCount(2);

    $invite();

    expect(cacheKept('/author/new-person/', '/author/someone/'))->toBe(['/author/someone/']);
});

it('purges a post\'s old and new addresses and the lists that show it, as the recompute moves it', function (): void {
    $story = pageArticle($this->editor, 'Harbour news');

    // The pattern changes with no purge of its own, as between two of the recompute's batches.
    DB::table('settings')->updateOrInsert(['group_key' => 'addresses'], ['overrides' => json_encode(['pattern_post' => '/news/{slug}/'])]);
    Cache::memo()->forget(SettingsRepository::cacheKey('addresses'));
    app()->forgetInstance(AddressSettings::class);

    cachePage('/harbour-news/');
    cachePage('/news/harbour-news/');
    cacheShown(['/lists-posts/' => ['list:post'], '/lists-pages/' => ['list:page']]);
    $pages = ['/harbour-news/', '/news/harbour-news/', '/lists-posts/', '/lists-pages/'];
    $rebuild = fn () => app(RebuildPaths::class)->execute(new RebuildPathsInput('content', [$story->id], (string) $this->admin->id));

    cacheRolledBack($rebuild);

    expect(cacheKept(...$pages))->toHaveCount(4);

    $rebuild();

    expect(cacheKept(...$pages))->toBe(['/lists-pages/']);
});
