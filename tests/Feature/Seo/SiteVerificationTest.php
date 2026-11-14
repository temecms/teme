<?php

declare(strict_types=1);

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Seo\SiteVerification;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->save = fn (array $tags) => $this->actingAs($this->admin)->from('/admin/settings/seo')
        ->put('/admin/settings/seo', ['values' => [SiteVerification::TAGS => $tags], 'expected_version' => 0]);
});

it('registers Site verification at 500, a list of up to 20 name and content pairs (I-288)', function (): void {
    $sections = array_map(fn (SearchAndSocialSection $section): array => [$section->order(), $section->key(), $section->label()], SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)));
    $setting = app(SettingsRegistry::class)->find('seo')?->setting(SiteVerification::TAGS);

    expect($sections)->toContain([500, 'site-verification', 'Site verification'])
        ->and($setting?->type)->toBe(SettingType::Pairs)
        ->and($setting?->label)->toBe('Site verification')
        ->and($setting?->description)->toBe('One name and content pair per service.')
        ->and($setting?->options)->toBe(['name' => 'Name', 'value' => 'Content'])
        ->and([$setting?->max, $setting?->maxLength])->toBe([20, 500])
        ->and(SiteVerification::tags())->toBe([]);
});

it('writes each tag on the home page, its text escaped, and on no other page (I-288)', function (): void {
    cmSettings([SiteVerification::TAGS => [
        ['name' => 'google-site-verification', 'value' => 'r8Kq"2&x'],
        ['name' => 'msvalidate.01', 'value' => '5F2A9C07'],
    ]], SearchAndSocial::KEY);
    headPost(User::factory()->create()->assignRole('author'), 'Harbour news');

    $home = (string) addrGet('/')->assertOk()->getContent();
    $dom = headDom('/');

    expect($home)->toContain('<meta name="google-site-verification" content="r8Kq&quot;2&amp;x">')
        ->and(headValue($dom, '//head/meta[@name="msvalidate.01"]/@content'))->toBe('5F2A9C07')
        ->and((int) headDom('/harbour-news/')->evaluate('count(//head/meta[@name="google-site-verification"] | //head/meta[@name="msvalidate.01"])'))->toBe(0);
});

it('refuses a name that isn\'t a meta tag\'s, one core writes in any case, a row missing its name or content, and a whole pasted tag, naming the first row that fails', function (string $case, string $message): void {
    $good = ['name' => 'google-site-verification', 'value' => 'abc'];
    $rows = match ($case) {
        'a name with a space' => [$good, ['name' => 'site verification', 'value' => 'abc']],
        'robots, in capitals' => [['name' => 'ROBOTS', 'value' => 'index']],
        'twitter:card' => [$good, ['name' => '', 'value' => ''], ['name' => 'Twitter:Card', 'value' => 'summary']],
        'description' => [['name' => 'description', 'value' => 'A site.']],
        'no content' => [$good, ['name' => 'msvalidate.01', 'value' => '']],
        'no name' => [['name' => '', 'value' => 'abc']],
        'one name twice' => [$good, ['name' => 'msvalidate.01', 'value' => 'x'], ['name' => 'Google-Site-Verification', 'value' => 'def']],
        default => [['name' => 'google-site-verification', 'value' => '<meta name="google-site-verification" content="abc">']],
    };

    ($this->save)($rows)->assertSessionHasErrors(['values.site_verification' => $message]);

    expect(SiteVerification::tags())->toBe([]);
})->with([
    'a name with a space' => ['a name with a space', 'Row 2: use letters and numbers in the name, joined by dots, hyphens, colons or underscores, as in google-site-verification.'],
    'robots, in capitals' => ['robots, in capitals', 'Row 1: Teme writes the ROBOTS tag itself, so it can’t be a verification tag.'],
    'twitter:card' => ['twitter:card', 'Row 3: Teme writes the Twitter:Card tag itself, so it can’t be a verification tag.'],
    'description' => ['description', 'Row 1: Teme writes the description tag itself, so it can’t be a verification tag.'],
    'no content' => ['no content', 'Row 2: paste the content the service gave you.'],
    'no name' => ['no name', 'Row 1: give the tag its name, such as google-site-verification.'],
    'one name twice' => ['one name twice', 'Row 3: row 1 has this name already, and a page carries one tag of each name.'],
    'a whole pasted tag' => ['a whole pasted tag', 'Row 1: paste only the text inside content="…", not the whole tag.'],
]);

it('saves good tags through the settings page, and an empty list clears them', function (): void {
    ($this->save)([['name' => 'google-site-verification', 'value' => 'abc'], ['name' => 'p:domain_verify', 'value' => 'def']])->assertSessionHasNoErrors();

    expect(SiteVerification::tags())->toBe([['name' => 'google-site-verification', 'value' => 'abc'], ['name' => 'p:domain_verify', 'value' => 'def']]);

    $this->actingAs($this->admin)->put('/admin/settings/seo', ['values' => [SiteVerification::TAGS => ''], 'expected_version' => 1])->assertSessionHasNoErrors();

    expect(SiteVerification::tags())->toBe([]);
});

it('leaves out of the head a stored row the checks refuse, as an import could write, and core\'s robots meta stays core\'s', function (): void {
    cmSettings([SiteVerification::TAGS => [
        ['name' => 'robots', 'value' => 'index, follow'],
        ['name' => 'bad name', 'value' => 'x'],
        ['name' => 'google-site-verification', 'value' => 'ok'],
    ]], SearchAndSocial::KEY);

    $dom = headDom('/');

    expect((int) $dom->evaluate('count(//head/meta[@name="robots"])'))->toBe(1)
        ->and(headValue($dom, '//head/meta[@name="robots"]/@content'))->not->toBe('index, follow')
        ->and(headValue($dom, '//head/meta[@name="google-site-verification"]/@content'))->toBe('ok')
        ->and(SiteVerification::tags())->toBe([['name' => 'google-site-verification', 'value' => 'ok']]);
});
