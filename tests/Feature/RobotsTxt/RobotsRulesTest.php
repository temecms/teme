<?php

declare(strict_types=1);

use App\Domain\RobotsTxt\RobotsRules;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\SettingsCheck;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('passes rules in any case, notes, blank lines, and an Allow or Disallow left empty', function (): void {
    expect(RobotsRules::firstProblem("User-agent: Googlebot-Image\nDISALLOW: /private/\n\n# Print pages\nallow: /print/public/\nDisallow:\nDisallow: *.pdf\$\nSitemap: https://example.test/news-sitemap.xml\nuser-agent: *"))->toBeNull();
});

it('names the first line that breaks the grammar, by its number, and how to mend it (I-866)', function (string $text, string $problem): void {
    expect(RobotsRules::firstProblem($text))->toBe($problem);
})->with([
    'not a rule' => ["User-agent: *\nCrawl-delay: 10", 'Line 2 isn’t a robots.txt rule. Start each line with User-agent:, Allow:, Disallow: or Sitemap:, or with # for a note.'],
    'a path without its slash' => ["# Print pages\n\nDisallow: private/", 'Line 3: start the path after Disallow: with /.'],
    'a sitemap without its site' => ["Sitemap: /sitemap.xml\nAllow: nope", 'Line 1: a Sitemap line needs a full address, starting https://.'],
    'no crawler named' => ["User-agent:\nAllow: /", 'Line 1: name the crawler after User-agent:, or write * for all of them.'],
]);

it('reads a browser\'s line breaks as one, so each line has the number the person sees, and keeps only the lines that pass', function (): void {
    expect(RobotsRules::firstProblem("User-agent: *\r\nDisallow: /a/\r\nAllow: b"))->toBe('Line 3: start the path after Allow: with /.')
        ->and(RobotsRules::kept("\r\nUser-agent: *\r\nDisallow: /a/  \r\n\r\nnot a rule\r\nAllow: /b/\r\n\r\n"))->toBe(['User-agent: *', 'Disallow: /a/', '', 'Allow: /b/']);
});

it('registers robots.txt as Search and social\'s section at 700, its extra rules empty and its AI switch off (I-300)', function (): void {
    $sections = array_map(fn (SearchAndSocialSection $section): array => [$section->order(), $section->key(), $section->label()], SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)));
    $group = app(SettingsRegistry::class)->find('seo');

    expect($sections)->toContain([700, 'robots-txt', 'robots.txt'])
        ->and($group?->setting('robots_extra_rules')?->label)->toBe('Extra rules')
        ->and($group?->setting('robots_extra_rules')?->description)->toBe('Added to the robots.txt Teme writes, after its own rules. Each line is checked: User-agent, Allow, Disallow or Sitemap, or # for a note.')
        ->and($group?->setting('robots_extra_rules')?->maxLength)->toBe(20000)
        ->and($group?->setting('discourage_ai_training')?->label)->toBe('Ask AI training crawlers not to use this site')
        ->and($group?->setting('discourage_ai_training')?->description)->toBe('Asks each AI training crawler on Teme’s list not to use the site, while still letting search engines in. It’s a request, not a lock: well-behaved crawlers follow it. It also keeps the site out of answers in Google’s Gemini apps, but not out of Google’s AI Overviews, which are part of Search, or pages a reader asks an assistant to fetch.')
        ->and(app(SettingsReader::class)->string('seo', 'robots_extra_rules'))->toBe('')
        ->and(app(SettingsReader::class)->bool('seo', 'discourage_ai_training'))->toBeFalse();
});

it('checks the extra rules when Search and social is saved, and keeps nothing while a line is wrong (I-866)', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings/seo')
        ->put('/admin/settings/seo', ['values' => ['robots_extra_rules' => "User-agent: *\r\nDisallow: private/"], 'expected_version' => 0])
        ->assertSessionHasErrors(['values.robots_extra_rules' => 'Line 2: start the path after Disallow: with /.']);

    expect(app(SettingsReader::class)->string('seo', 'robots_extra_rules'))->toBe('');

    $this->actingAs($this->admin)
        ->put('/admin/settings/seo', ['values' => ['robots_extra_rules' => "User-agent: *\r\nDisallow: /private/", 'discourage_ai_training' => '1'], 'expected_version' => 0])
        ->assertSessionHasNoErrors();

    expect(app(SettingsReader::class)->string('seo', 'robots_extra_rules'))->toBe("User-agent: *\r\nDisallow: /private/")
        ->and(app(SettingsReader::class)->bool('seo', 'discourage_ai_training'))->toBeTrue();
});

it('asks the checks registered for the group being saved, and only those', function (): void {
    app()->instance('test.check.general', new class implements SettingsCheck
    {
        public function group(): string
        {
            return 'general';
        }

        public function problems(array $values): array
        {
            return ['values.site_title' => 'Not this one.'];
        }
    });
    app()->tag(['test.check.general'], SettingsCheck::TAG);

    $this->actingAs($this->admin)->from('/admin/settings/general')
        ->put('/admin/settings/general', ['values' => ['site_title' => 'Ledger'], 'expected_version' => 0])
        ->assertSessionHasErrors(['values.site_title' => 'Not this one.']);
    $this->actingAs($this->admin)
        ->put('/admin/settings/seo', ['values' => ['robots_extra_rules' => 'Disallow: /print/'], 'expected_version' => 0])
        ->assertSessionHasNoErrors();

    expect(app(SettingsReader::class)->string('general', 'site_title'))->toBe('My Teme site')
        ->and(app(SettingsReader::class)->string('seo', 'robots_extra_rules'))->toBe('Disallow: /print/');
});
