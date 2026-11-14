<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The pages the Rich Results Test is run on by hand before a release
 * (I-706): each drawn from these fixtures, its graph checked for what that
 * test reads, and its HTML kept in storage/framework/testing/rich-results/
 * for a person to paste into the test's code tab, the site's address
 * written as https://www.example.com, a name kept for examples.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    sdTrailTheme();
    $this->author = User::factory()->create(['name' => 'Ada Okafor'])->assignRole('author');
    $this->author->forceFill(['bio' => 'Covers the harbour board and the ferries.'])->save();
    app(PathProjection::class)->syncUser($this->author);
    $this->editor = User::factory()->create(['name' => 'Tunde Briggs'])->assignRole('editor');
    cmSettings(['site_title' => 'The Harbour Ledger', 'tagline' => 'News from the harbour'], 'general');
    cmSettings(['publisher_type' => 'Organization', 'publisher_name' => 'The Harbour Ledger', 'publisher_logo' => pageImage($this->editor, 600, 600)->id], 'seo');
});

it('draws each kind of page the Rich Results Test is run on with the nodes it reads, and keeps its HTML to paste (I-706)', function (string $kind): void {
    $ferries = sdCategory('Ferries', sdCategory('News'));
    $post = headPost($this->author, 'Ferry fares rise in the spring', [BuiltInFields::EXCERPT => 'The harbour board sets next year’s fares.'], [$ferries], [], pageImage($this->author, 1600, 900));
    addrPublished($this->editor, 'About the Ledger', 'page');
    $current = fn (string $type, string $id): string => '/'.DB::table('paths')->where('target_type', $type)->where('target_id', $id)->where('is_current', true)->value('path').'/';

    if ($kind === 'a local business') {
        cmSettings([
            'publisher_type' => 'Restaurant',
            'publisher_name' => 'The Harbour Kitchen',
            'business_street_address' => '1 Quay Street',
            'business_locality' => 'Port Harcourt',
            'business_postal_code' => '500001',
            'business_country' => 'NG',
            'business_phone' => '+234 84 000 0000',
            'business_price_range' => '$$',
        ], 'seo');
    }

    $address = match ($kind) {
        'a post' => $current('content', $post->id),
        'a page' => '/about-the-ledger/',
        'an archive' => '/category/news/ferries/',
        'an author\'s page' => $current('user', (string) $this->author->id),
        default => '/',
    };
    $expected = match ($kind) {
        'a post' => ['WebSite', 'Organization', 'WebPage', 'BlogPosting', 'Person', 'ImageObject', 'BreadcrumbList'],
        'a page' => ['WebSite', 'Organization', 'WebPage'],
        'the home page' => ['WebSite', 'Organization', 'CollectionPage'],
        'an archive' => ['WebSite', 'Organization', 'CollectionPage', 'BreadcrumbList'],
        'an author\'s page' => ['WebSite', 'Organization', 'ProfilePage', 'Person'],
        default => ['WebSite', 'Restaurant', 'CollectionPage'],
    };

    $html = (string) addrGet($address)->assertOk()->getContent();
    $types = collect(sdGraph($address))->flatMap(fn (array $node): array => (array) ($node['@type'] ?? []))->unique()->values()->all();
    $folder = storage_path('framework/testing/rich-results');
    $file = $folder.'/'.Str::slug($kind).'.html';

    // The suite's parallel processes may each make it at once; whichever is second finds it made.
    if (! is_dir($folder)) {
        @mkdir($folder, 0775, true);
    }

    File::put($file, str_replace('http://localhost', 'https://www.example.com', $html));

    expect($types)->toContain(...$expected)
        ->and((string) file_get_contents($file))->toContain('"@id":"https://www.example.com/#website"')
        ->not->toContain('http://localhost');
})->with(['a post', 'a page', 'the home page', 'an archive', 'an author\'s page', 'a local business']);
