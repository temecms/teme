<?php

declare(strict_types=1);

use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\SiteLanguage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

it('offers Japanese, in its own name, left to right, searched word for word', function (): void {
    expect(config('languages.ja'))->toBe(['label' => 'Japanese', 'search' => 'simple', 'native' => '日本語', 'direction' => 'ltr', 'og' => 'ja_JP'])
        ->and(app(SiteLanguage::class)->searchConfig('ja'))->toBe('simple');
});

it('serves a Japanese version under its prefix, saying ja', function (): void {
    $this->seed(RoleSeeder::class);
    $site = LanguageSite::build();
    Languages::set(['fr', 'es', 'ja'], hidden: ['es']);
    LanguageSite::version($site->editor, 'Konnichiwa', 'ja', $site->postGroup, [], publish: true);

    expect(app(SiteLanguages::class)->native('ja'))->toBe('日本語')
        ->and(app(SiteLanguages::class)->ogLocale('ja'))->toBe('ja_JP');

    addrGet('/hello/')->assertOk()->assertSee('hreflang="ja"', false);
});
