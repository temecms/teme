<?php

declare(strict_types=1);

use App\Domain\Media\ImageMarkup;
use App\Domain\Site\PageWarnings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create()->assignRole('author');
});

it('draws srcset, dimensions, sizes from the theme, and lazy loading', function (): void {
    $media = pageImage($this->author, 2000, 1200, focalX: 30, focalY: 70);

    $html = app(ImageMarkup::class)->render($media->id, 'content');

    expect($html)
        ->toContain('width="2000"')->toContain('height="1200"')
        ->toContain('sizes="(min-width: 720px) 680px, 100vw"')
        ->toContain(' 320w')->toContain(' 1920w')->toContain(' 2000w')->not->toContain(' 2560w')
        ->toContain('loading="lazy"')
        ->toContain('object-position: 30% 70%')
        ->not->toContain('fetchpriority');
});

it('marks the priority image and counts it', function (): void {
    $media = pageImage($this->author, 1600, 900);

    $html = app(ImageMarkup::class)->render($media->id, 'content', priority: true);

    expect($html)->toContain('fetchpriority="high"')->not->toContain('loading=')
        ->and(app(PageWarnings::class)->priorityCount())->toBe(1);
});

it('warns about a context the theme does not declare, and still draws the image', function (): void {
    $media = pageImage($this->author, 800, 600);

    expect(app(ImageMarkup::class)->render($media->id, 'no-such-context'))->toContain('sizes="100vw"')
        ->and(app(PageWarnings::class)->all())->toContain('The theme declares no image context "no-such-context".');
});

it('writes an empty alt for a decorative image, and the given alt otherwise', function (): void {
    $media = pageImage($this->author, 800, 600);

    expect(app(ImageMarkup::class)->render($media->id, 'content', decorative: true))->toContain('alt=""')
        ->and(app(ImageMarkup::class)->render($media->id, 'content', alt: 'A heron'))->toContain('alt="A heron"');
});
