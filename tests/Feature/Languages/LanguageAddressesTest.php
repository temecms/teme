<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Operations\Input\RebuildPathsInput;
use App\Domain\Addresses\Operations\RebuildPaths;
use App\Domain\Addresses\Paths;
use App\Domain\Contents\Models\Content;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

/**
 * Every path row, as the table holds it.
 *
 * @return list<array<string, mixed>>
 */
function laddrPaths(): array
{
    return DB::table('paths')->orderBy('path')->get(['path', 'target_type', 'target_id', 'is_current'])->map(fn (object $row): array => (array) $row)->all();
}

/**
 * A published page of this language under this parent, written to the rows
 * as the tree's own tests write a parent.
 */
function laddrChild(User $editor, string $title, ?string $language, ?string $parentId): Content
{
    $page = lifecycleDraft($editor, $title, 'page');
    DB::table('contents')->where('id', $page->id)->update(['language' => $language, 'parent_id' => $parentId]);
    listsPublish($page, $editor);

    return $page->refresh();
}

it('gives every existing item, term and author the address it had before this release', function (): void {
    $admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $editor = User::factory()->create(['name' => 'Eddie Editor'])->assignRole('editor');
    addrChange($admin, ['patterns' => ['post' => '/{year}/{month}/{slug}/', 'page' => '/{parent}/{slug}/']]);

    $news = typesTerm($admin, 'category', 'News');
    typesTerm($admin, 'category', 'Local', $news->id);
    $home = addrPublished($editor, 'Welcome', 'page');
    $about = addrPublished($editor, 'About', 'page');
    laddrChild($editor, 'Team', null, $about->id);
    addrPublished($editor, 'Hello');
    addrChange($admin, ['patterns' => ['post' => '/{year}/{month}/{slug}/', 'page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $home->id]);

    $before = laddrPaths();

    foreach (['content' => DB::table('contents')->pluck('id'), 'term' => DB::table('terms')->pluck('id'), 'user' => DB::table('users')->pluck('id')] as $kind => $ids) {
        app(RebuildPaths::class)->execute(new RebuildPathsInput($kind, $ids->map(fn (mixed $id): string => (string) $id)->all(), (string) $admin->id));
    }

    expect(laddrPaths())->toBe($before)
        ->and(addrCurrent('content', $home->id))->toBe('');
});

it('puts the prefix before a French item\'s address and leaves the default\'s alone', function (): void {
    $site = LanguageSite::build();

    expect(addrCurrent('content', $site->postFr))->toBe('fr/bonjour')
        ->and(addrCurrent('content', $site->post))->toBe('hello');
});

it('puts the prefix before a French term\'s address', function (): void {
    $site = LanguageSite::build();

    expect(addrCurrent('term', $site->newsFr))->toBe('fr/category/actualites')
        ->and(addrCurrent('term', $site->news))->toBe('category/news');
});

it('gives the home page\'s French version the prefix alone', function (): void {
    $site = LanguageSite::build();
    $admin = User::query()->where('name', 'Ada Admin')->firstOrFail();
    $editor = User::query()->where('name', 'Eddie Editor')->firstOrFail();
    addrChange($admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $site->page]);

    listsPublish(Content::query()->findOrFail($site->pageFr), $editor);

    expect(addrCurrent('content', $site->page))->toBe('')
        ->and(addrCurrent('content', $site->pageFr))->toBe('fr');
});

it('keeps every other language\'s prefix, shown or hidden, for the site itself', function (): void {
    $site = LanguageSite::build();

    expect(Paths::taken('fr'))->toBeTrue()
        ->and(Paths::taken('es'))->toBeTrue()
        ->and(Paths::taken('it'))->toBeFalse()
        ->and(Paths::conflict('fr', 'content', $site->post))->toBe('The address /fr/ is used by the site itself.')
        ->and(Paths::conflict('fr/bonjour', 'content', $site->postFr))->toBeNull();
});

it('keeps the prefix on the pages under a French page when it moves', function (): void {
    $site = LanguageSite::build();
    $editor = User::query()->where('name', 'Eddie Editor')->firstOrFail();
    $aPropos = Content::query()->findOrFail($site->pageFr);
    listsPublish($aPropos, $editor);
    $equipe = laddrChild($editor, 'Equipe', 'fr', $site->pageFr);
    $team = laddrChild($editor, 'Team', null, $site->page);
    // The default language's page at the address the French page would have without its prefix.
    $other = addrPublished($editor, 'Qui sommes nous', 'page');
    $held = laddrChild($editor, 'Equipe', null, $other->id);

    expect(addrCurrent('content', $equipe->id))->toBe('fr/a-propos/equipe')
        ->and(addrCurrent('content', $held->id))->toBe('qui-sommes-nous/equipe');

    addrRename($aPropos, 'qui-sommes-nous', $editor);

    expect(addrCurrent('content', $equipe->id))->toBe('fr/qui-sommes-nous/equipe')
        ->and(DB::table('paths')->where('target_id', $equipe->id)->where('path', 'fr/a-propos/equipe')->value('is_current'))->toBeFalse()
        ->and(addrCurrent('content', $team->id))->toBe('about/team');
});

it('refuses a type whose addresses would start with a language\'s prefix', function (): void {
    $admin = ctAdmin();
    siteSetLanguage($admin, 'en-GB');
    Languages::set(['fr']);
    ctCreate($admin);

    expect(fn () => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition(['segment' => 'fr']), (string) $admin->id)))
        ->toThrow(ValidationException::class, 'Addresses starting /fr/ are used by the site itself.')
        ->and(fn () => ctCreate($admin, ['key' => 'reel', 'singular' => 'Reel', 'plural' => 'Reels', 'segment' => 'fr']))
        ->toThrow(ValidationException::class, 'Addresses starting /fr/ are used by the site itself.');
});

it('reads no language setting when a type is saved with its address unchanged', function (): void {
    $admin = ctAdmin();
    ctCreate($admin);
    Cache::memo()->forget(SettingsRepository::cacheKey('languages'));
    Cache::forget(SettingsRepository::cacheKey('languages'));
    app()->forgetScopedInstances();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = [$query->sql, $query->bindings];
    });

    app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition(['plural' => 'Movies']), (string) $admin->id));

    expect(collect($statements)->filter(fn (array $statement): bool => str_contains($statement[0], '"settings"') && in_array('languages', $statement[1], true))->all())->toBe([]);
});
