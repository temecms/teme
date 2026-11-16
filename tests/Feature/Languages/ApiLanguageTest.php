<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    Switches::apiOn();
});

it('answers a French item by its ID and its address, saying fr', function (): void {
    $this->getJson('/api/v1/items/'.$this->site->postFr)->assertOk()->assertJsonPath('data.language', 'fr');
    $this->getJson('/api/v1/items/by-path?path='.rawurlencode('/fr/bonjour/'))->assertOk()
        ->assertJsonPath('data.id', $this->site->postFr)
        ->assertJsonPath('data.language', 'fr');
});

it('gives the same answer whatever Accept-Language or cookie a client sends', function (): void {
    $plain = $this->getJson('/api/v1/items')->assertOk()->json();
    $asked = $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])->withUnencryptedCookie('locale', 'fr')->getJson('/api/v1/items')->assertOk()->json();

    expect($asked)->toBe($plain);
});

it('lists French items and terms at ?language=fr', function (): void {
    $items = array_column($this->getJson('/api/v1/items?language=fr')->assertOk()->json('data'), 'title');
    $terms = array_column($this->getJson('/api/v1/terms?language=fr')->assertOk()->json('data'), 'name');
    $filed = array_column($this->getJson('/api/v1/items?language=fr&term='.$this->site->newsFr)->assertOk()->json('data'), 'title');

    expect($items)->toContain('Bonjour')->not->toContain('Hello')->not->toContain('Hola')
        ->and($terms)->toBe(['Actualités'])
        ->and($filed)->toBe(['Bonjour']);
});

it('lists the default language\'s items for its own tag, as for none', function (): void {
    $none = $this->getJson('/api/v1/items')->assertOk()->json('data');
    $named = $this->getJson('/api/v1/items?language=en-GB')->assertOk()->json('data');

    expect($named)->toBe($none)
        ->and(array_column($named, 'title'))->toContain('Hello')->not->toContain('Bonjour');
});

it('refuses a language the site does not show, in a typo\'s words', function (string $list): void {
    $hidden = $this->getJson("/api/v1/{$list}?language=es")->assertStatus(400)->json();
    $unknown = $this->getJson("/api/v1/{$list}?language=xx")->assertStatus(400)->json();

    expect($hidden['code'])->toBe('invalid_parameter')
        ->and($hidden['errors'])->toBe($unknown['errors'])
        ->and($hidden['errors'][0]['parameter'])->toBe('language')
        ->and($hidden['errors'][0]['detail'])->toContain('en-GB, fr')->not->toContain('es');
})->with(['items', 'terms']);

it('gives every full item its translations, [] when it has none', function (): void {
    $hello = $this->getJson('/api/v1/items/'.$this->site->post)->assertOk()->json('data.translations');
    $bonjour = $this->getJson('/api/v1/items/'.$this->site->postFr)->assertOk()->json('data.translations');

    expect($hello)->toBe([['id' => $this->site->postFr, 'language' => 'fr', 'path' => '/fr/bonjour/', 'url' => 'http://localhost/fr/bonjour/']])
        ->and($bonjour)->toBe([['id' => $this->site->post, 'language' => 'en-GB', 'path' => '/hello/', 'url' => 'http://localhost/hello/']])
        ->and($this->getJson('/api/v1/items/'.$this->site->alone)->assertOk()->json('data.translations'))->toBe([]);
});

it('lists the site\'s shown languages, the default first', function (): void {
    expect($this->getJson('/api/v1')->assertOk()->json('data.languages'))->toBe([
        ['tag' => 'en-GB', 'name' => 'English (United Kingdom)', 'native' => 'English (United Kingdom)', 'path' => '/', 'url' => 'http://localhost/', 'default' => true],
        ['tag' => 'fr', 'name' => 'French', 'native' => 'Français', 'path' => '/fr/', 'url' => 'http://localhost/fr/', 'default' => false],
    ]);
});
