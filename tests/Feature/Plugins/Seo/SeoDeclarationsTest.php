<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Seo\SeoValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Teme\Contracts\Sitemaps\PluginSitemap;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Seokit\NewsSitemap;
use Tests\Fixtures\Plugins\Seokit\Seokit;

require_once __DIR__.'/Seokit.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->manifest = PluginFixtures::manifest('seokit');
    $this->contributes = $this->manifest['contributes'];
    $this->errors = fn (array $contributes, array $capabilities = ['content.read', 'pages.head', 'sitemaps']): array => app(ContributionsValidator::class)->errors($contributes, '1.0.0', $capabilities);
});

afterEach(fn () => Seokit::reset());

it('accepts the fixture’s checks, section, sitemap and terms', function (): void {
    expect(($this->errors)($this->contributes))->toBe([]);
});

it('refuses each declaration out of bounds, in one plain sentence each', function (array $changes, string $error): void {
    $contributes = $this->contributes;

    foreach ($changes as [$path, $value]) {
        if ($value === null) {
            Arr::forget($contributes, $path);
        } else {
            Arr::set($contributes, $path, $value);
        }
    }

    expect(($this->errors)($contributes))->toContain($error);
})->with([
    'checks not a list' => [[['editorChecks', ['a' => ['id' => 'aa']]]], 'Its editor checks must be a list.'],
    'a check id in capitals' => [[['editorChecks.0.id', 'Key']], 'An editor check’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.'],
    'two checks of one id' => [[['editorChecks.1.id', 'keyphrase']], 'Two editor checks can’t share the id keyphrase.'],
    'a check of an unknown key' => [[['editorChecks.0.script', 'x.js']], 'An editor check can’t have script.'],
    'a check with no label' => [[['editorChecks.0.label', '']], 'An editor check’s label must be 1 to 60 characters.'],
    'a section as a list' => [[['searchSection', [['label' => 'A']]]], 'Its Search and social section must be an object.'],
    'a long tab label' => [[['searchSection.label', str_repeat('a', 25)]], 'A Search and social section’s label must be 1 to 24 characters.'],
    'an unknown position' => [[['searchSection.position', 'first']], 'A Search and social section’s position must be after-search, after-social or last.'],
    'no fields' => [[['searchSection.fields', []]], 'A Search and social section needs a list of fields.'],
    'nine fields' => [[['searchSection.fields', array_map(fn (int $n): array => ['key' => "f{$n}", 'type' => 'text', 'label' => 'F'], range(1, 9))]], 'A Search and social section can hold at most 8 fields.'],
    'a field key in capitals' => [[['searchSection.fields.0.key', 'Focus']], 'A Search and social field’s key must be lower-case letters, digits and underscores, starting with a letter.'],
    'two fields of one key' => [[['searchSection.fields.1.key', 'focus']], 'Two Search and social fields can’t share the key focus.'],
    'a field of an unknown type' => [[['searchSection.fields.0.type', 'color']], 'A Search and social field’s type must be text, textarea, number, boolean, select or url.'],
    'a maxLength on a number' => [[['searchSection.fields.1.maxLength', 3]], 'Only a text or textarea field takes a maxLength, from 1 to 2,000.'],
    'a select of no options' => [[['searchSection.fields.2.options', []]], 'A select field needs its options, each a value and a label.'],
    'a min on text' => [[['searchSection.fields.0.min', 1]], 'Only a number field takes a min, and it must be a number.'],
    'five sitemaps' => [[['sitemaps', array_map(fn (int $n): array => ['id' => "map-{$n}", 'label' => 'Map'], range(1, 5))]], 'A plugin can add at most 4 sitemaps.'],
    'a sitemap id in capitals' => [[['sitemaps.0.id', 'News']], 'A sitemap’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.'],
    'a type in lower case' => [[['structuredData.types.0', 'faqPage']], 'A structured data type is a schema.org type’s name, such as FAQPage.'],
    'a property in capitals' => [[['structuredData.properties.0', 'MainEntity']], 'A structured data property is a schema.org property’s name, such as acceptedAnswer.'],
    'an unknown term key' => [[['structuredData.classes', ['X']]], 'Its structured data terms can’t have classes.'],
]);

it('refuses checks, sitemaps and terms without the capability each needs, naming it', function (): void {
    expect(($this->errors)($this->contributes, ['pages.head', 'sitemaps']))->toContain('It adds editor checks, but the plugin doesn’t ask for content.read.')
        ->and(($this->errors)($this->contributes, ['content.read', 'pages.head']))->toContain('It adds sitemaps, but the plugin doesn’t ask for sitemaps.')
        ->and(($this->errors)($this->contributes, ['content.read', 'sitemaps']))->toContain('It declares structured data terms, but the plugin doesn’t ask for pages.head.');
});

it('refuses, as the plugin starts, checks or sitemaps its class doesn’t answer', function (): void {
    $validator = app(SeoValidator::class);

    expect($validator->startErrors($this->contributes, new Seokit))->toBe([])
        ->and($validator->startErrors($this->contributes, new stdClass))->toContain(
            'It declares editor checks but doesn’t implement Teme\\Contracts\\Editor\\HasEditorChecks.',
            'It declares sitemaps but doesn’t implement Teme\\Contracts\\Sitemaps\\HasSitemaps.',
        )
        ->and($validator->startErrors([], new Seokit))->toContain('It runs editor checks but declares none.', 'It hands over sitemaps but declares none.')
        ->and($validator->startErrors([...$this->contributes, 'sitemaps' => [['id' => 'events', 'label' => 'Events']]], new Seokit))->toContain(
            'It declares the sitemap “events” but has no PluginSitemap for it.',
            'It has a sitemap “news”, which isn’t declared.',
        );

    expect(new NewsSitemap)->toBeInstanceOf(PluginSitemap::class);
});

it('says in the consent what each declaration may do, on the plugins screen and at the shell', function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('seokit');
    $admin = User::factory()->create()->assignRole('administrator');
    $row = collect($this->actingAs($admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->firstWhere('name', 'tests/seokit');
    $lines = [
        'Add tags that search engines and social sites read, and structured data, to the head of your site’s public pages',
        'Add its own sitemaps to your site’s sitemap index',
        'Check the Posts, Pages you edit and show what it finds beside the editor’s own checks: Focus keyphrase, Text length.',
        'Add a tab, “SEO Kit”, to Search and social for Posts, whose values are saved with each item.',
        'Its sitemaps: News.',
    ];

    expect(array_column($row['asks'], 'words'))->toContain(...$lines);

    $command = $this->artisan('teme:plugins:enable', ['name' => 'tests/seokit']);

    foreach (array_slice($lines, 2) as $line) {
        $command->expectsOutputToContain($line);
    }

    $command->assertFailed();

    PluginFixtures::forget();
});
