<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

it('answers a French item at its prefixed address, in French', function (): void {
    addrGet('/fr/bonjour/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour, in a few words.')
        ->assertDontSee('Hello, in a few words.');
});

it('answers the default language\'s addresses exactly as before', function (): void {
    addrGet('/hello/')->assertOk()
        ->assertHeader('Content-Language', 'en-GB')
        ->assertSee('Hello, in a few words.');
});

it('redirects a French address to its canonical spelling', function (): void {
    expect(addrLocation(addrGet('/fr/bonjour')->assertStatus(301)))->toBe('/fr/bonjour/')
        ->and(addrLocation(addrGet('/fr')->assertStatus(301)))->toBe('/fr/');
});

it('lists a French term\'s French items at its prefixed address', function (): void {
    addrGet('/fr/category/actualites/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('Hello');
});

it('lists an author\'s French items under the prefix and their default-language items without it', function (): void {
    app(PathProjection::class)->syncUser($this->site->editor);
    $author = '/'.addrCurrent('user', (string) $this->site->editor->id).'/';

    addrGet('/fr'.$author)->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('Hello')
        ->assertDontSee('Alone');

    addrGet($author)->assertOk()
        ->assertHeader('Content-Language', 'en-GB')
        ->assertSee('Hello')
        ->assertSee('Alone')
        ->assertDontSee('Bonjour')
        ->assertDontSee('Hola');
});

it('lists a type\'s French items at its archive\'s address after the prefix', function (): void {
    ctCreate($this->site->admin);
    LanguageSite::version($this->site->editor, 'Le film', 'fr', null, [], publish: true, type: 'film');
    LanguageSite::version($this->site->editor, 'The film', null, null, [], publish: true, type: 'film');

    addrGet('/fr/films/')->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Le film')
        ->assertDontSee('The film');

    addrGet('/films/')->assertOk()
        ->assertSee('The film')
        ->assertDontSee('Le film');
});

it('lists a month\'s French items after the prefix', function (): void {
    $month = now()->format('Y/m');

    addrGet("/fr/{$month}/")->assertOk()
        ->assertHeader('Content-Language', 'fr')
        ->assertSee('Bonjour')
        ->assertDontSee('Hello');
});

it('answers a search at /fr/ in French', function (): void {
    $response = addrGet('/fr/?s=bonjour')->assertOk()->assertHeader('Content-Language', 'fr');

    expect($response->headers->get('X-Teme-Address'))->toBe('search');
});

it('answers 404 in French for an address under the prefix with nothing at it', function (): void {
    addrGet('/fr/nothing-here/')->assertNotFound()->assertHeader('Content-Language', 'fr');
});

it('redirects an old French address to the item\'s current one', function (): void {
    addrRename(Content::query()->findOrFail($this->site->postFr), 'salut', $this->site->editor);

    expect(addrLocation(addrGet('/fr/bonjour/')->assertStatus(301)))->toBe('/fr/salut/');
});

it('reads no prefix for the default language', function (): void {
    addrGet('/en-gb/hello/')->assertNotFound()->assertHeader('Content-Language', 'en-GB');
});
