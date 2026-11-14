<?php

declare(strict_types=1);

use App\Domain\Status\Checks\LanguageHomeShadowsRedirect;
use App\Domain\Status\StatusChecks;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    siteSetLanguage($this->admin, 'en-GB');
});

it('warns whoever manages redirects when a redirect\'s source is a language\'s prefix', function (): void {
    // Saved before French was added, while /fr/ was free.
    rdSaved('/fr/', '/francais/');
    Languages::set(['fr']);

    $banner = app(LanguageHomeShadowsRedirect::class)->banner();

    expect($banner?->message)->toBe('The redirect from /fr/ no longer answers: French readers\' home page is there now.')
        ->and($banner?->actionLabel)->toBe('See the redirect')
        ->and($banner?->actionUrl)->toBe('/admin/tools/redirects?search=%2Ffr')
        ->and(app(StatusChecks::class)->ownerOf(LanguageHomeShadowsRedirect::class))->toBe('core');
});

it('shows no banner while no redirect starts there', function (): void {
    rdSaved('/francais/', '/fr/');
    rdSaved('/fr/old/', '/fr/');
    Languages::set(['fr']);

    expect(app(LanguageHomeShadowsRedirect::class)->banner())->toBeNull();
});

it('shows the banner to nobody without redirects.manage', function (): void {
    rdSaved('/fr/', '/francais/');
    Languages::set(['fr']);
    $author = User::factory()->create()->assignRole('author');

    expect(app(LanguageHomeShadowsRedirect::class)->ability())->toBe('redirects.manage')
        ->and($author->can('redirects.manage'))->toBeFalse()
        ->and(app(StatusChecks::class)->forUser($author)?->message)->not->toBe(app(LanguageHomeShadowsRedirect::class)->banner()?->message);
});
