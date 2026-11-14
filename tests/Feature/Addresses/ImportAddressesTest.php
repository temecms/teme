<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Operations\ImportAddresses;
use App\Domain\Addresses\Operations\Input\ImportAddressesInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * ImportAddresses as $actor, or as the operator at the server's shell.
 *
 * @param  array<string, string>  $patterns
 * @param  array<string, string>  $bases
 *
 * @return array{recompute: bool, actorId: string|null}
 */
function iaImport(?User $actor, array $patterns = [], array $bases = [], ?string $authorBase = null, ?string $frontShows = null, ?string $frontPageId = null, ?string $postsPageId = null): array
{
    $import = fn (): array => app(ImportAddresses::class)->execute(new ImportAddressesInput($patterns, $bases, $authorBase, $frontShows, $frontPageId, $postsPageId, $actor === null ? null : (string) $actor->id));

    return $actor === null ? scElevated($import) : $import();
}

it('sets the address settings as the import’s, and moves every address already here, the old one kept (I-393)', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    expect(addrCurrent('content', $post->id))->toBe('harbour-news');

    $result = iaImport(null, ['post' => '/{year}/{slug}/'], ['category' => 'topics']);
    app()->forgetInstance(AddressSettings::class);

    expect($result)->toBe(['recompute' => true, 'actorId' => null])
        ->and(AddressSettings::current()->patterns['post'])->toBe('/{year}/{slug}/')
        ->and(AddressSettings::current()->bases['category'])->toBe('topics')
        ->and(addrCurrent('content', $post->id))->toBe(now('UTC')->format('Y').'/harbour-news')
        ->and(addrGet('/harbour-news/')->getStatusCode())->toBe(301)
        ->and(DB::table('settings_changes')->where('group_key', 'addresses')->pluck('origin')->all())->toBe(['import']);
});

it('changes only what it is given, and moves nothing when nothing that makes an address changed', function (): void {
    $before = AddressSettings::current();

    $result = iaImport($this->admin, frontShows: AddressSettings::FRONT_LATEST, authorBase: 'writers');
    app()->forgetInstance(AddressSettings::class);

    expect($result)->toBe(['recompute' => true, 'actorId' => (string) $this->admin->id])
        ->and(AddressSettings::current()->patterns)->toBe($before->patterns)
        ->and(AddressSettings::current()->bases)->toBe($before->bases)
        ->and(AddressSettings::current()->authorBase)->toBe('writers')
        ->and(iaImport($this->admin, frontShows: AddressSettings::FRONT_LATEST)['recompute'])->toBeFalse();
});

it('refuses what Settings › Addresses refuses, writing nothing', function (array $given, string $field): void {
    $errors = [];

    try {
        iaImport(null, ...$given);
    } catch (ValidationException $refused) {
        $errors = $refused->errors();
    }

    expect(array_keys($errors))->toContain($field)
        ->and(DB::table('settings_changes')->count())->toBe(0);
})->with([
    'a pattern without {slug}' => [['patterns' => ['post' => '/{year}/']], 'patterns.post'],
    'the site’s own first part' => [['patterns' => ['post' => '/admin/{slug}/']], 'patterns.post'],
    'a type Teme hasn’t' => [['patterns' => ['recipe' => '/{slug}/']], 'patterns.recipe'],
    'a base with a slash' => [['bases' => ['category' => 'a/b']], 'bases.category'],
    'an author base of nothing' => [['authorBase' => ''], 'authorBase'],
    'a home page that isn’t a published page' => [['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => '01jvm0000000000000000000aa'], 'frontPageId'],
]);

it('refuses settings that would put two things already here at one address, naming them', function (): void {
    addrPublished($this->admin, 'News', 'page');
    taxonomyTerm('category', 'News');

    $errors = [];

    try {
        iaImport(null, bases: ['category' => '']);
    } catch (ValidationException $refused) {
        $errors = $refused->errors();
    }

    expect($errors)->toBe(['addresses.0' => ['The category "News" and the page "News" would both be at /news/.']])
        ->and(DB::table('settings_changes')->count())->toBe(0);
});

it('lets only the operator and those who may import set them, writing nothing otherwise', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => iaImport($editor, ['post' => '/{year}/{slug}/']))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(fn () => app(ImportAddresses::class)->execute(new ImportAddressesInput(['post' => '/{year}/{slug}/'], [], null, null, null, null, null)))->toThrow(AuthorizationException::class)
        ->and(DB::table('settings_changes')->count())->toBe(0);
});
