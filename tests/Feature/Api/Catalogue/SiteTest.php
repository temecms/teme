<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Api\CatalogueProbe;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

it('names the site, its address, language, zone and home', function (): void {
    CatalogueProbe::general($this->site->admin, ['site_title' => 'The Harbour Times', 'tagline' => 'News from the quay']);

    $response = $this->get('/api/v1')->assertOk()->assertHeader('Content-Type', 'application/json');

    expect(array_keys((array) $response->json()))->toBe(['data', 'links'])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['name', 'tagline', 'url', 'language', 'timezone', 'home'])
        ->and($response->json('data.name'))->toBe('The Harbour Times')
        ->and($response->json('data.tagline'))->toBe('News from the quay')
        ->and($response->json('data.url'))->toBe('http://localhost/')
        ->and($response->json('data.language'))->toBe('en-GB')
        ->and($response->json('data.timezone'))->toBe('Africa/Lagos')
        ->and($response->json('data.home'))->toBe(['shows' => 'latest', 'page_id' => null, 'posts_page_id' => null])
        ->and(array_keys($response->json('links')))->toEqualCanonicalizing(['self', 'items', 'types', 'taxonomies', 'terms'])
        ->and($response->json('links'))->toBe([
            'self' => 'http://localhost/api/v1',
            'items' => 'http://localhost/api/v1/items',
            'types' => 'http://localhost/api/v1/types',
            'taxonomies' => 'http://localhost/api/v1/taxonomies',
            'terms' => 'http://localhost/api/v1/terms',
        ]);
});

it('names the site with none of its secret settings, its administration email or Teme\'s version', function (): void {
    CatalogueProbe::general($this->site->admin, ['admin_email' => 'owner-only@secret.example']);
    aiKey('anthropic_key', 'sk-anthropic-never-shown');
    config(['app.version' => '9.8.7-secret', 'teme.version' => '9.8.7-secret']);

    $body = (string) $this->get('/api/v1')->assertOk()->getContent();

    expect($body)->not->toContain('owner-only@secret.example')
        ->not->toContain('admin_email')
        ->not->toContain('sk-anthropic-never-shown')
        ->not->toContain('9.8.7-secret')
        ->not->toContain('version')
        ->not->toContain('theme')
        ->not->toContain('plugin');
});

it('gives the home page\'s ID only while it is live and public', function (): void {
    $home = $this->site->about;
    $posts = addrPublished($this->site->admin, 'News', 'page');

    $this->actingAs($this->site->admin)->put('/admin/settings/reading/home-page', ['front_shows' => AddressSettings::FRONT_PAGE, 'front_page_id' => $home->id, 'posts_page_id' => $posts->id])
        ->assertSessionHasNoErrors();

    expect($this->get('/api/v1')->assertOk()->json('data.home'))->toBe(['shows' => 'page', 'page_id' => $home->id, 'posts_page_id' => $posts->id]);

    // The Operations refuse to unpublish the home page, so the state is made as another process's change leaves it.
    DB::table('contents')->where('id', $home->id)->update(['status' => 'draft']);

    expect($this->get('/api/v1')->json('data.home'))->toBe(['shows' => 'page', 'page_id' => null, 'posts_page_id' => $posts->id]);

    DB::table('contents')->where('id', $posts->id)->update(['visibility' => 'private']);

    expect($this->get('/api/v1')->json('data.home'))->toBe(['shows' => 'page', 'page_id' => null, 'posts_page_id' => null]);
});

it('refuses any parameter', function (): void {
    $this->get('/api/v1?colour=red')->assertStatus(400)->assertJsonPath('code', 'invalid_parameter')->assertJsonPath('errors.0.parameter', 'colour');
});

it('says nothing when the API is off', function (): void {
    Switches::apiOff();

    $this->get('/api/v1')->assertNotFound()->assertJsonPath('code', 'api_off');
});
