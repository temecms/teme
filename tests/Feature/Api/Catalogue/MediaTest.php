<?php

declare(strict_types=1);

use App\Domain\Api\ApiWords;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\CatalogueProbe;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
    $this->site = DeliverySite::build();
    Switches::apiOn();
});

it('answers a file a public item shows in its body, as its featured image, or in a shown field', function (string $how): void {
    $admin = $this->site->admin;
    $file = mediaUpload($admin, mediaJpeg(900, 600), 'Shown.jpg');
    $value = ['id' => $file->id, 'alt' => null, 'decorative' => false];

    match ($how) {
        'an image card' => CatalogueProbe::publishedMovie($admin, $this->site->author, 'Card', [], CatalogueProbe::imageBody($file->id)),
        'a link in the body' => CatalogueProbe::publishedMovie($admin, $this->site->author, 'Link', [], CatalogueProbe::linkBody($file->id)),
        'a featured image' => CatalogueProbe::publishedMovie($admin, $this->site->author, 'Featured', [BuiltInFields::FEATURED_IMAGE => $value], CatalogueProbe::plainBody()),
        'a shown field' => CatalogueProbe::publishedMovie($admin, $this->site->author, 'Poster', [DeliverySite::POSTER => $value], CatalogueProbe::plainBody()),
    };

    $response = $this->get('/api/v1/media/'.$file->id)->assertOk()->assertHeader('Content-Type', 'application/json');

    expect(array_keys((array) $response->json()))->toBe(['data', 'links'])
        ->and($response->json('links'))->toBe(['self' => 'http://localhost/api/v1/media/'.$file->id])
        ->and(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'kind', 'mime_type', 'alt', 'decorative', 'width', 'height', 'focal', 'url', 'sizes'])
        ->and($response->json('data.id'))->toBe($file->id)
        ->and($response->json('data.kind'))->toBe('image')
        ->and($response->json('data.decorative'))->toBeFalse()
        ->and([$response->json('data.width'), $response->json('data.height')])->toBe([900, 600])
        ->and($response->json('data.focal'))->toBe(['x' => 50, 'y' => 50]);
})->with(['an image card', 'a link in the body', 'a featured image', 'a shown field']);

it('answers the files the fixture\'s public item shows', function (string $name): void {
    $file = $this->site->{$name};

    $this->get('/api/v1/media/'.$file->id)->assertOk()->assertJsonPath('data.id', $file->id);
})->with(['shell', 'poster']);

it('never gives an image\'s original address, nor a storage path or an upload time', function (): void {
    $body = (string) $this->get('/api/v1/media/'.$this->site->shell->id)->assertOk()->getContent();
    $data = json_decode($body, true)['data'];

    expect($data['url'])->toBe(collect($data['sizes'])->sortByDesc('width')->first()['url'])
        ->and($body)->not->toContain('media-originals')
        ->and($body)->not->toContain('uploaded')
        ->and($body)->not->toContain('created_at')
        ->and($body)->not->toContain('teme:');
});

it('answers 404 for a file only a draft, a private item, a protected item, a region or a hidden field uses', function (string $name): void {
    $never = strtoupper((string) Str::ulid());
    $file = $this->site->{$name};

    $hidden = $this->get('/api/v1/media/'.$file->id)->assertNotFound();
    $none = $this->get('/api/v1/media/'.$never)->assertNotFound();

    expect($hidden->json('code'))->toBe('not_found')
        ->and($hidden->json('detail'))->toBe(ApiWords::noFile($file->id))
        ->and(str_replace($file->id, '<id>', (string) $hidden->getContent()))->toBe(str_replace($never, '<id>', (string) $none->getContent()))
        ->and($hidden->headers->get('Content-Type'))->toBe($none->headers->get('Content-Type'))
        ->and($hidden->headers->get('ETag'))->toBe($none->headers->get('ETag'));
})->with(['draftOnly', 'privateOnly', 'protectedOnly', 'hiddenOnly', 'regionOnly', 'socialOnly']);

it('answers 404 once the only item showing it is unpublished', function (): void {
    $admin = $this->site->admin;
    $file = mediaUpload($admin, mediaJpeg(901, 600), 'Soon gone.jpg');
    $movie = CatalogueProbe::publishedMovie($admin, $this->site->author, 'Soon gone', [BuiltInFields::FEATURED_IMAGE => ['id' => $file->id, 'alt' => null, 'decorative' => false]], CatalogueProbe::plainBody());

    $this->get('/api/v1/media/'.$file->id)->assertOk();

    app(UnpublishContent::class)->execute(new ContentActionInput($movie->id, (string) $admin->id));

    $this->get('/api/v1/media/'.$file->id)->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('answers 404 for a file in the trash', function (): void {
    $poster = $this->site->poster;

    $this->get('/api/v1/media/'.$poster->id)->assertOk();

    app(TrashMedia::class)->execute(new MediaActionInput($poster->id, (string) $this->site->admin->id));
    // The next request is a request of its own: the files one request read are not remembered into it.
    app()->forgetScopedInstances();

    $this->get('/api/v1/media/'.$poster->id)->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('gives the library\'s alt text', function (): void {
    $admin = $this->site->admin;
    $file = mediaUpload($admin, mediaJpeg(902, 600), 'Library.jpg');
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($file->id, (string) $admin->id, 'Library', 'The library’s words', null, null));
    CatalogueProbe::publishedMovie($admin, $this->site->author, 'Library item', [BuiltInFields::FEATURED_IMAGE => ['id' => $file->id, 'alt' => 'This use’s words', 'decorative' => false]], CatalogueProbe::plainBody());

    $this->get('/api/v1/media/'.$file->id)->assertOk()
        ->assertJsonPath('data.alt', 'The library’s words')
        ->assertJsonPath('data.decorative', false);
});

it('answers 404 for an ID of the wrong form with the API\'s own words', function (): void {
    $this->get('/api/v1/media/'.strtolower($this->site->poster->id))->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->get('/api/v1/media/not-an-id')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('reads a file in at most 5 statements', function (): void {
    expect(CatalogueProbe::statements(fn () => $this->get('/api/v1/media/'.$this->site->shell->id)->assertOk()))->toBeLessThanOrEqual(5)
        ->and(CatalogueProbe::statements(fn () => $this->get('/api/v1/media/'.$this->site->draftOnly->id)->assertNotFound()))->toBeLessThanOrEqual(5);
});

it('refuses any parameter on a file', function (): void {
    $this->get('/api/v1/media/'.$this->site->shell->id.'?size=large')->assertStatus(400)->assertJsonPath('errors.0.parameter', 'size');
});
