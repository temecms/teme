<?php

declare(strict_types=1);

use App\Domain\Languages\SiteLanguages;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\LanguageColumns;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('builds a site in three languages whose rows follow the rules', function (): void {
    $site = LanguageSite::build();
    $item = fn (string $id): object => DB::table('contents')->where('id', $id)->first(['language', 'translation_group', 'status', 'slug']);
    $term = fn (string $id): object => DB::table('terms')->where('id', $id)->first(['language', 'translation_group', 'slug']);

    expect([$item($site->post)->language, $item($site->postFr)->language, $item($site->postEs)->language])->toBe([null, 'fr', 'es'])
        ->and([$item($site->post)->translation_group, $item($site->postFr)->translation_group, $item($site->postEs)->translation_group])->toBe([$site->postGroup, $site->postGroup, $site->postGroup])
        ->and([$item($site->page)->language, $item($site->pageFr)->language])->toBe([null, 'fr'])
        ->and([$item($site->page)->translation_group, $item($site->pageFr)->translation_group])->toBe([$site->pageGroup, $site->pageGroup])
        ->and([$item($site->pageFr)->status, $item($site->pageFr)->slug])->toBe(['draft', 'a-propos'])
        ->and([$item($site->postFr)->status, $item($site->postFr)->slug])->toBe(['published', 'bonjour'])
        ->and([$term($site->news)->language, $term($site->newsFr)->language, $term($site->newsFr)->slug])->toBe([null, 'fr', 'actualites'])
        ->and([$term($site->news)->translation_group, $term($site->newsFr)->translation_group])->toBe([$site->newsGroup, $site->newsGroup])
        ->and([$item($site->alone)->language, $item($site->alone)->translation_group])->toBe([null, null])
        ->and(count(array_unique([$site->postGroup, $site->pageGroup, $site->newsGroup])))->toBe(3)
        ->and(LanguageColumns::defaultTagged())->toBe([])
        ->and(app(SiteLanguages::class)->shown())->toBe(['en-GB', 'fr']);
});
