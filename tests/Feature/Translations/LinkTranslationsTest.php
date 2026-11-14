<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\LinkTranslationsInput;
use App\Domain\Contents\Operations\Input\UnlinkTranslationInput;
use App\Domain\Contents\Operations\LinkTranslations;
use App\Domain\Contents\Operations\UnlinkTranslation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * @param  list<string>  $ids
 */
function ltLink(User $actor, array $ids): string
{
    return app(LinkTranslations::class)->execute(new LinkTranslationsInput($ids, (string) $actor->id));
}

function ltUnlink(User $actor, string $id): void
{
    app(UnlinkTranslation::class)->execute(new UnlinkTranslationInput($id, (string) $actor->id));
}

/**
 * Each item's translation group, in the order asked.
 *
 * @param  list<string>  $ids
 *
 * @return list<string|null>
 */
function ltGroups(array $ids): array
{
    $groups = DB::table('contents')->whereIn('id', $ids)->pluck('translation_group', 'id')->all();

    return array_map(fn (string $id): ?string => $groups[$id] ?? null, $ids);
}

it('joins two items, and every member of their groups, into one group', function (): void {
    $seul = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);

    $group = ltLink($this->site->editor, [$this->site->alone, $seul]);
    expect(ltGroups([$this->site->alone, $seul]))->toBe([$group, $group])
        ->and($group)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($group)->not->toBeIn([$this->site->alone, $seul]);

    // A Spanish item with no group, named first, joins the group of the other, French member included.
    $solo = LanguageSite::version($this->site->editor, 'Solo', 'es', null, [], publish: true);

    expect(ltLink($this->site->editor, [$solo, $this->site->alone]))->toBe($group)
        ->and(ltGroups([$solo, $this->site->alone, $seul]))->toBe([$group, $group, $group]);
});

it('refuses two versions in one language, naming both', function (): void {
    $before = ltGroups([$this->site->alone, $this->site->post]);

    expect(fn () => ltLink($this->site->editor, [$this->site->alone, $this->site->post]))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['items'])->toBe(['Both would be in English (United Kingdom). Each language can have one version.']);
    });
    expect(ltGroups([$this->site->alone, $this->site->post]))->toBe($before);
});

it('refuses items of different types', function (): void {
    expect(fn () => ltLink($this->site->editor, [$this->site->alone, $this->site->pageFr]))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['items'])->toBe(['Choose posts: a translation is of the same type.']);
    });
});

it('refuses an item in the trash', function (): void {
    $seul = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);
    pageTrash(Content::query()->findOrFail($seul), $this->site->editor);

    expect(fn () => ltLink($this->site->editor, [$this->site->alone, $seul]))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['items'])->toBe(['Restore it first.']);
    });
    expect(ltGroups([$this->site->alone]))->toBe([null]);
});

it('takes one version out of its group and leaves the others linked', function (): void {
    ltUnlink($this->site->editor, $this->site->postEs);

    expect(ltGroups([$this->site->post, $this->site->postFr, $this->site->postEs]))->toBe([$this->site->postGroup, $this->site->postGroup, null]);
});

it('purges the pages of every version whose group changed', function (): void {
    $folder = cacheOn();

    try {
        $seul = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);
        cacheShown([
            '/hello/' => ['translations:'.$this->site->postGroup],
            '/fr/bonjour/' => ['content:'.$this->site->postFr],
            '/alone/' => ['content:'.$this->site->alone],
            '/fr/seul/' => ['content:'.$seul],
            '/category/news/' => ['term:'.$this->site->news],
        ]);

        ltUnlink($this->site->editor, $this->site->postEs);
        expect(cacheKept('/hello/', '/fr/bonjour/'))->toBe([]);

        ltLink($this->site->editor, [$this->site->alone, $seul]);
        expect(cacheKept('/alone/', '/fr/seul/', '/category/news/'))->toBe(['/category/news/']);
    } finally {
        cacheOff($folder);
    }
});

it('writes nothing when the person may not edit one of the items', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $mine = LanguageSite::version($author, 'Mine', 'fr', null, [], publish: false);
    $before = ltGroups([$mine, $this->site->alone, $this->site->postEs]);

    expect(fn () => ltLink($author, [$mine, $this->site->alone]))->toThrow(AuthorizationException::class)
        ->and(fn () => ltUnlink($author, $this->site->postEs))->toThrow(AuthorizationException::class);
    expect(ltGroups([$mine, $this->site->alone, $this->site->postEs]))->toBe($before);
});

/**
 * Each address row of an item, current or not.
 *
 * @return array<string, bool>
 */
function ltPaths(string $id): array
{
    return DB::table('paths')->where('target_type', 'content')->where('target_id', $id)->orderBy('path')->pluck('is_current', 'path')->all();
}

it('moves a published page linked to the home page\'s group to its language\'s home, the old address redirecting', function (): void {
    $front = LanguageSite::version($this->site->editor, 'Home', null, null, [], publish: true, type: 'page');
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $front]);
    $home = LanguageSite::version($this->site->editor, 'Accueil', 'fr', null, [], publish: true, type: 'page');
    $folder = cacheOn();

    try {
        addrGet('/fr/accueil/')->assertOk();
        expect(ltPaths($home))->toBe(['fr/accueil' => true]);

        ltLink($this->site->editor, [$front, $home]);

        expect(ltPaths($home))->toBe(['fr' => true, 'fr/accueil' => false])
            ->and(addrLocation(addrGet('/fr/accueil/')->assertStatus(301)))->toBe('/fr/');
        addrGet('/fr/')->assertOk()->assertSee('Accueil, in a few words.');
    } finally {
        cacheOff($folder);
    }
});

it('gives a published page unlinked from the home page\'s group its own address again, the old one kept as history', function (): void {
    $front = LanguageSite::version($this->site->editor, 'Home', null, null, [], publish: true, type: 'page');
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $front]);
    $home = LanguageSite::version($this->site->editor, 'Accueil', 'fr', null, [], publish: true, type: 'page');
    ltLink($this->site->editor, [$front, $home]);
    $folder = cacheOn();

    try {
        addrGet('/fr/')->assertOk()->assertSee('Accueil, in a few words.');

        ltUnlink($this->site->editor, $home);

        expect(ltPaths($home))->toBe(['fr' => false, 'fr/accueil' => true]);
        addrGet('/fr/accueil/')->assertOk()->assertSee('Accueil, in a few words.');
        addrGet('/fr/')->assertOk()->assertDontSee('Accueil, in a few words.')->assertSee('Bonjour');
    } finally {
        cacheOff($folder);
    }
});

it('takes the pages\' tree lock before any row when it links pages', function (): void {
    $home = LanguageSite::version($this->site->editor, 'Home', null, null, [], publish: true, type: 'page');
    $accueil = LanguageSite::version($this->site->editor, 'Accueil', 'fr', null, [], publish: true, type: 'page');
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
    });

    ltLink($this->site->editor, [$home, $accueil]);

    $lock = collect($statements)->search(fn (array $statement): bool => str_contains($statement['sql'], 'pg_advisory_xact_lock(') && in_array('teme.tree.page', $statement['bindings'], true));
    $row = collect($statements)->search(fn (array $statement): bool => str_contains($statement['sql'], 'for update'));

    expect($lock)->toBeInt()
        ->and($row)->toBeInt()
        ->and($lock)->toBeLessThan($row);
});

it('moves the other language\'s home page to its own address when the default home page is the one unlinked, the home page\'s own unchanged', function (): void {
    $front = LanguageSite::version($this->site->editor, 'Home', null, null, [], publish: true, type: 'page');
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $front]);
    $home = LanguageSite::version($this->site->editor, 'Accueil', 'fr', null, [], publish: true, type: 'page');
    ltLink($this->site->editor, [$front, $home]);
    $frontPaths = ltPaths($front);
    $folder = cacheOn();

    try {
        addrGet('/fr/')->assertOk()->assertSee('Accueil, in a few words.');

        ltUnlink($this->site->editor, $front);

        expect(ltPaths($home))->toBe(['fr' => false, 'fr/accueil' => true])
            ->and(ltPaths($front))->toBe($frontPaths);
        addrGet('/fr/accueil/')->assertOk()->assertSee('Accueil, in a few words.');
        addrGet('/fr/')->assertOk()->assertDontSee('Accueil, in a few words.')->assertSee('Bonjour');
        addrGet('/')->assertOk()->assertSee('Home, in a few words.');
    } finally {
        cacheOff($folder);
    }
});

it('refuses an unlink whose moved item\'s new address another target holds, whole, and writes nothing', function (string $unlinked): void {
    $front = LanguageSite::version($this->site->editor, 'Home', null, null, [], publish: true, type: 'page');
    addrChange($this->site->admin, ['patterns' => ['page' => '/{parent}/{slug}/', 'post' => '/{slug}/'], 'frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $front]);
    $home = LanguageSite::version($this->site->editor, 'Accueil', 'fr', null, [], publish: true, type: 'page');
    $group = ltLink($this->site->editor, [$front, $home]);
    // A French post now holds the address the page would move back to.
    $post = LanguageSite::version($this->site->editor, 'Accueil', 'fr', null, [], publish: true);
    expect(DB::table('paths')->where('path', 'fr/accueil')->where('is_current', true)->value('target_id'))->toBe($post);
    $before = [ltGroups([$front, $home]), ltPaths($front), ltPaths($home), ltPaths($post)];

    expect(fn () => ltUnlink($this->site->editor, $unlinked === 'front' ? $front : $home))
        ->toThrow(ValidationException::class, 'The address /fr/accueil/ is used by the post "Accueil".');

    expect([ltGroups([$front, $home]), ltPaths($front), ltPaths($home), ltPaths($post)])->toBe($before)
        ->and(ltGroups([$front, $home]))->toBe([$group, $group]);
})->with(['the French page' => ['home'], 'the default home page' => ['front']]);
