<?php

declare(strict_types=1);

use App\Domain\Addresses\Operations\ChangeAddresses;
use App\Domain\Addresses\Operations\Input\ChangeAddressesInput;
use App\Domain\Languages\PrefixConflicts;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    siteSetLanguage($this->admin, 'en-GB');
});

it('refuses a prefix something already holds, naming it', function (string $prefix, Closure $hold, string $words): void {
    $hold($this);

    expect(PrefixConflicts::for($prefix))->toBe($words);
})->with([
    'a reserved word' => ['admin', fn () => null, 'The address /admin/ is used by the site itself.'],
    'a site route' => ['feed', fn () => null, 'The address /feed/ is used by the site itself.'],
    'the API' => ['api', fn () => null, 'The address /api/ is used by the site itself.'],
    'another language\'s prefix' => ['fr', fn () => Languages::set(['de'], ['de' => 'fr']), 'Another language already uses /fr/.'],
    'a page at the prefix' => ['fr', fn ($test) => addrPublished($test->editor, 'Fr', 'page'), 'The address /fr/ is used by the page "Fr".'],
    'a page under the prefix' => ['fr', function ($test): void {
        addrChange($test->admin, ['patterns' => ['page' => '/fr/{slug}/']]);
        addrPublished($test->editor, 'Bonjour', 'page');
    }, 'Pages addresses start with /fr/: change them in Settings › Addresses first.'],
    'a pattern starting with the prefix' => ['fr', fn ($test) => addrChange($test->admin, ['patterns' => ['post' => '/fr/{slug}/']]), 'Posts addresses start with /fr/: change them in Settings › Addresses first.'],
    'a term base' => ['fr', fn ($test) => addrChange($test->admin, ['bases' => ['category' => 'fr']]), 'The category base is fr: change it in Settings › Addresses first.'],
    'the author base' => ['fr', fn ($test) => addrChange($test->admin, ['authorBase' => 'fr']), 'The author base is fr: change it in Settings › Addresses first.'],
    'a type\'s archive page' => ['fr', function ($test): void {
        ctCreate($test->admin);
        app(ChangeAddresses::class)->execute(new ChangeAddressesInput(['post' => '/{slug}/', 'page' => '/{parent}/{slug}/', 'film' => '/films/{slug}/'], ['category' => 'category', 'tag' => 'tag'], 'author', 'latest', '', '', (string) $test->admin->id, ['film' => 'fr']));
    }, 'The address /fr/ is used by the archive page "Films".'],
]);

it('allows a prefix that only an old address or a redirect holds', function (): void {
    $page = addrPublished($this->editor, 'Fr', 'page');
    addrRename($page, 'francais', $this->editor);
    rdSaved('/fr/', '/francais/');

    expect(PrefixConflicts::for('fr'))->toBeNull();
});

it('lets a language keep its own prefix and the addresses under it', function (): void {
    Languages::set(['fr']);
    $post = addrPublished($this->editor, 'Bonjour');
    DB::table('contents')->where('id', $post->id)->update(['language' => 'fr']);
    DB::table('paths')->where('target_id', $post->id)->update(['path' => 'fr/bonjour']);

    expect(PrefixConflicts::for('fr', 'fr'))->toBeNull()
        ->and(PrefixConflicts::for('fr'))->toBe('Another language already uses /fr/.');
});
