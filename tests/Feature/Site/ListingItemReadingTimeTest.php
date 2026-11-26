<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ListingViews;
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
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    app(PathProjection::class)->syncUser($this->author);
});

it('gives a listing item the reading time its article gives, without reading its body', function (): void {
    $short = pageArticle($this->author, 'Short', words: 50);
    $long = pageArticle($this->author, 'Long', words: 500);
    $items = app(ListingViews::class)->items([(string) $short->id, (string) $long->id]);

    expect($items[0]->readingMinutes)->toBe(1)
        ->and($items[1]->readingMinutes)->toBe(3)
        ->and($items[1]->readingMinutes)->toBe(app(ArticleViews::class)->published($long->refresh())->readingMinutes);
});
