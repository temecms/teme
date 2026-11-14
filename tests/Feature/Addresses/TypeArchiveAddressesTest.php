<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Operations\ImportAddresses;
use App\Domain\Addresses\Operations\Input\ImportAddressesInput;
use App\Domain\Addresses\PathLinkResolver;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ltTypes();
    // A type's archive-page rows, as [address, current], by address.
    $this->rows = fn (string $type): array => DB::table('paths')->where('target_type', 'type')->where('target_id', $type)->orderBy('path')
        ->get(['path', 'is_current'])->map(fn (object $row): array => [(string) $row->path, (bool) $row->is_current])->all();
});

it('moves Films’ archive page to the address given, keeps the old one as history, and queues no recompute (I-412)', function (): void {
    $result = archMove($this->admin, ['film' => 'movies']);

    expect($result['recompute'])->toBeFalse()
        ->and(TypeArchives::path('film'))->toBe('movies')
        ->and(app(SettingsReader::class)->string('addresses', 'archive_film'))->toBe('movies')
        ->and(($this->rows)('film'))->toBe([['films', false]]);
});

it('keeps one old address a move, and drops the one the page comes back to', function (): void {
    archMove($this->admin, ['film' => 'movies']);
    archMove($this->admin, ['film' => 'cinema']);
    archMove($this->admin, ['film' => 'films']);

    expect(($this->rows)('film'))->toBe([['cinema', false], ['movies', false]]);
});

it('takes an old address over from a page’s history, but never a page’s live address', function (): void {
    $movies = addrPublished($this->admin, 'Movies', 'page');
    addrRename($movies, 'our-movies', $this->admin);

    archMove($this->admin, ['film' => 'movies']);
    archMove($this->admin, ['film' => 'films']);

    expect(($this->rows)('film'))->toBe([['movies', false]])
        ->and(DB::table('paths')->where('target_type', 'content')->where('target_id', $movies->id)->pluck('path')->all())->toBe(['our-movies']);

    $reels = archReelsAfterPage($this->admin);
    archMove($this->admin, ['reel' => 'all-reels']);

    expect(($this->rows)('reel'))->toBe([])
        ->and(addrCurrent('content', $reels->id))->toBe('reels');
});

it('refuses an address an archive page can’t have, writing nothing', function (array $archives, string $message): void {
    expect(fn () => archMove($this->admin, $archives))->toThrow(ValidationException::class, $message)
        ->and(app(SettingsReader::class)->string('addresses', 'archive_film'))->toBe('films')
        ->and(DB::table('paths')->where('target_type', 'type')->count())->toBe(0);
})->with([
    'two words' => [['film' => 'films/all'], 'An archive page’s address needs lower-case letters, numbers, hyphens or underscores.'],
    'capitals' => [['film' => 'Films'], 'An archive page’s address needs lower-case letters, numbers, hyphens or underscores.'],
    'nothing' => [['film' => ''], 'An archive page’s address needs lower-case letters, numbers, hyphens or underscores.'],
    'a year' => [['film' => '2024'], 'An archive page’s address can’t be a number or “page”: dates and a list’s later pages use them.'],
    'page' => [['film' => 'page'], 'An archive page’s address can’t be a number or “page”: dates and a list’s later pages use them.'],
    'the admin' => [['film' => 'admin'], 'Addresses starting /admin/ are used by the site itself.'],
    'the feed' => [['film' => 'feed'], 'Addresses starting /feed/ are used by the site itself.'],
    'a type with no archive' => [['recipe' => 'recipes'], 'There is no archive page for [recipe].'],
    'posts, whose list is the home page' => [['post' => 'posts'], 'There is no archive page for [post].'],
]);

it('refuses two archive pages at one address, naming both', function (): void {
    $this->travel(1)->minutes();
    ltDocumentaries();

    expect(fn () => archMove($this->admin, ['documentary' => 'films']))
        ->toThrow(ValidationException::class, 'The archive page "Films" and the archive page "Documentaries" would both be at /films/.');
});

it('refuses an archive page the address a page has, and a page an archive page’s address (I-3304)', function (): void {
    addrPublished($this->admin, 'Movies', 'page');

    expect(fn () => archMove($this->admin, ['film' => 'movies']))
        ->toThrow(ValidationException::class, 'The archive page "Films" and the page "Movies" would both be at /movies/.')
        ->and(fn () => addrPublished($this->admin, 'Films', 'page'))
        ->toThrow(ValidationException::class, 'The address /films/ is used by the archive page "Films".');
});

it('saves other changes while a page holds an archive page’s address, which the screen names instead of refusing', function (): void {
    archReelsAfterPage($this->admin);

    archMove($this->admin, ['film' => 'cinema', 'reel' => 'reels']);
    addrChange($this->admin, ['authorBase' => 'writer']);

    expect(TypeArchives::path('film'))->toBe('cinema')
        ->and(TypeArchives::path('reel'))->toBe('reels')
        ->and(TypeArchives::holder('reel'))->toBe('the page "Reels"');
});

it('takes an address with an underscore, as a type’s own pattern may start with one', function (): void {
    ctCreate(null, ['key' => 'reel', 'singular' => 'Reel', 'plural' => 'Reels', 'segment' => 'reels', 'icon' => 'film', 'pattern' => '/my_reels/{slug}/']);

    expect(TypeArchives::path('reel'))->toBe('my_reels');

    archMove($this->admin, ['reel' => 'my_reels', 'film' => 'my_films']);

    expect(TypeArchives::path('reel'))->toBe('my_reels')
        ->and(TypeArchives::path('film'))->toBe('my_films');
});

it('reads an old row under an archive page’s address as no link, never the page that used to be there', function (): void {
    $movies = addrPublished($this->admin, 'Movies', 'page');
    addrRename($movies, 'our-movies', $this->admin);

    expect(app(PathLinkResolver::class)->targetAt('/movies/'))->toBe(['kind' => 'content', 'id' => (string) $movies->id]);

    archMove($this->admin, ['film' => 'movies']);

    expect(app(PathLinkResolver::class)->targetAt('/movies/'))->toBeNull();
});

it('writes nothing when the person may not change the addresses', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => archMove($editor, ['film' => 'movies']))->toThrow(AuthorizationException::class, 'Not permitted to change the addresses.')
        ->and(app(SettingsReader::class)->string('addresses', 'archive_film'))->toBe('films')
        ->and(DB::table('settings_changes')->where('group_key', 'addresses')->count())->toBe(0)
        ->and(DB::table('paths')->where('target_type', 'type')->count())->toBe(0);
});

it('brings an archive page’s address with an import, recorded as the import’s, the old one kept', function (): void {
    app(ImportAddresses::class)->execute(new ImportAddressesInput([], [], null, null, null, null, (string) $this->admin->id, archives: ['film' => 'movies']));
    app()->forgetInstance(AddressSettings::class);

    expect(TypeArchives::path('film'))->toBe('movies')
        ->and(DB::table('settings_changes')->where('group_key', 'addresses')->pluck('origin')->all())->toBe(['import'])
        ->and(($this->rows)('film'))->toBe([['films', false]]);
});

it('lets an archive page move to the slug of an item that has no address there: a private one, or the home page', function (string $case): void {
    $movies = addrPublished($this->admin, 'Movies', 'page');

    if ($case === 'private') {
        Visibilities::change($movies, 'private', $this->admin);
    } else {
        addrChange($this->admin, ['frontShows' => 'page', 'frontPageId' => (string) $movies->id]);
    }

    archMove($this->admin, ['film' => 'movies']);

    expect(TypeArchives::path('film'))->toBe('movies');
})->with(['private', 'home page']);

it('names a live post at the address an archive page moves to once', function (): void {
    addrPublished($this->admin, 'Movies', 'post');

    try {
        archMove($this->admin, ['film' => 'movies']);
        $errors = [];
    } catch (ValidationException $exception) {
        $errors = array_merge(...array_values($exception->errors()));
    }

    expect($errors)->toBe(['The archive page "Films" and the post "Movies" would both be at /movies/.']);
});

it('locks the addresses row on a site that never saved them, writing it first so the first two saves wait for each other', function (string $operation): void {
    DB::table('settings')->where('group_key', 'addresses')->delete();
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, '"settings"')) {
            $statements[] = $query->sql;
        }
    });

    if ($operation === 'ChangeAddresses') {
        archMove($this->admin, ['film' => 'movies']);
    } else {
        app(ImportAddresses::class)->execute(new ImportAddressesInput([], [], null, null, null, null, (string) $this->admin->id, archives: ['film' => 'movies']));
    }

    $first = fn (string $needle): ?int => array_key_first(array_filter($statements, fn (string $sql): bool => str_contains($sql, $needle)));

    expect(DB::table('settings')->where('group_key', 'addresses')->exists())->toBeTrue()
        ->and($first('on conflict do nothing'))->not->toBeNull()
        ->and($first('on conflict do nothing'))->toBeLessThan($first('for update'));
})->with(['ChangeAddresses', 'ImportAddresses']);

it('names an address of a kind this release doesn’t know in words, refusing an archive page, a published page or a restore there, never failing', function (): void {
    $page = addrPublished($this->admin, 'Jane Roe', 'page');
    pageTrash($page, $this->admin);
    // A kind a later release adds, with a ULID, takes the trashed page's address.
    DB::table('paths')->where('path', 'jane-roe')->delete();
    DB::table('paths')->insert([
        ['path' => 'movies', 'target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d', 'is_current' => true],
        ['path' => 'jane-roe', 'target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3e', 'is_current' => true],
        ['path' => 'jane-doe', 'target_type' => 'gadget', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3f', 'is_current' => true],
    ]);

    expect(fn () => archMove($this->admin, ['film' => 'movies']))
        ->toThrow(ValidationException::class, 'The archive page "Films" and something this version of Teme doesn’t know (gadget) would both be at /movies/.')
        ->and(fn () => addrPublished($this->admin, 'Jane Doe', 'page'))
        ->toThrow(ValidationException::class, 'The address /jane-doe/ is used by something this version of Teme doesn’t know (gadget).')
        ->and(app(RestoreContent::class)->execute(new ContentActionInput($page->id, (string) $this->admin->id))->slug)
        ->toBe('jane-roe-2');
});
