<?php

declare(strict_types=1);

use App\Domain\RobotsTxt\AiTrainingCrawlers;
use App\Domain\RobotsTxt\RobotsTxt;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->folder = null;
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('answers /robots.txt from Teme as plain text, keeping crawlers from the admin and core\'s endpoints, and nothing more by default (I-300)', function (): void {
    addrGet('/robots.txt')->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent("User-agent: *\nDisallow: /admin/\nDisallow: /_teme/\n\nSitemap: http://localhost/sitemap.xml\n");

    expect(public_path('robots.txt'))->not->toBeFile();
});

it('adds the extra rules after its own, each line as written, a browser\'s line breaks read as one', function (): void {
    cmSettings(['robots_extra_rules' => "User-agent: Googlebot-Image\r\nDisallow: /private/\r\n\r\n# Print pages\r\nDisallow: /print/"], 'seo');

    expect(RobotsTxt::text())->toBe("User-agent: *\nDisallow: /admin/\nDisallow: /_teme/\n\nUser-agent: Googlebot-Image\nDisallow: /private/\n\n# Print pages\nDisallow: /print/\n\nSitemap: http://localhost/sitemap.xml\n");
});

it('leaves out a line that breaks the grammar, even one written without the check', function (): void {
    cmSettings(['robots_extra_rules' => "Disallow: /print/\nCrawl-delay: 10\n<script>alert(1)</script>"], 'seo');

    expect(RobotsTxt::text())->toBe("User-agent: *\nDisallow: /admin/\nDisallow: /_teme/\n\nDisallow: /print/\n\nSitemap: http://localhost/sitemap.xml\n");
});

it('asks each AI training crawler on Teme\'s list to stay away, after the extra rules, with the Content-Signal line in every crawler\'s group, while the switch is on (I-707)', function (): void {
    cmSettings(['discourage_ai_training' => true, 'robots_extra_rules' => 'Disallow: /print/'], 'seo');

    expect(RobotsTxt::text())->toBe(implode("\n", [
        'User-agent: *',
        'Content-Signal: search=yes, ai-train=no',
        'Disallow: /admin/',
        'Disallow: /_teme/',
        '',
        'Disallow: /print/',
        '',
        "# AI training crawlers, from Teme's list",
        'User-agent: Amazonbot',
        'Disallow: /',
        '',
        'User-agent: Applebot-Extended',
        'Disallow: /',
        '',
        'User-agent: Bytespider',
        'Disallow: /',
        '',
        'User-agent: CCBot',
        'Disallow: /',
        '',
        'User-agent: ClaudeBot',
        'Disallow: /',
        '',
        'User-agent: Google-Extended',
        'Disallow: /',
        '',
        'User-agent: GPTBot',
        'Disallow: /',
        '',
        'User-agent: meta-externalagent',
        'Disallow: /',
        '',
        'User-agent: MistralAI-Training',
        'Disallow: /',
        '',
        'User-agent: AI2Bot',
        'Disallow: /',
        '',
        'Sitemap: http://localhost/sitemap.xml',
    ])."\n");
});

it('names training crawlers only, Cloudflare\'s eight first, each with its operator and its source (I-867)', function (): void {
    $agents = AiTrainingCrawlers::agents();
    $neverTraining = ['Applebot', 'OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'Perplexity-User', 'ChatGPT-User', 'Claude-User', 'Google-Agent', 'Googlebot', 'facebookexternalhit', 'Meta-ExternalFetcher', 'Meta-WebIndexer', 'MistralAI-User', 'MistralAI-Index'];

    expect(array_slice($agents, 0, 8))->toBe(['Amazonbot', 'Applebot-Extended', 'Bytespider', 'CCBot', 'ClaudeBot', 'Google-Extended', 'GPTBot', 'meta-externalagent'])
        ->and($agents)->toBe(array_values(array_unique($agents)))
        ->and(array_values(array_intersect(array_map(strtolower(...), $agents), array_map(strtolower(...), $neverTraining))))->toBe([]);

    foreach (AiTrainingCrawlers::LIST as $crawler) {
        expect($crawler['agent'])->toMatch('/^[A-Za-z0-9-]+$/')
            ->and($crawler['operator'])->not->toBe('')
            ->and($crawler['source'])->toStartWith('https://');
    }
});

it('says the same while the site discourages search engines but for the sitemap\'s line, since every page then says noindex, which a crawler kept out would never read (I-883)', function (): void {
    cmSettings(['discourage_ai_training' => true], 'seo');
    $public = RobotsTxt::text();

    cmSettings([WholeSite::DISCOURAGE => true], 'seo');

    expect($public)->toEndWith("Disallow: /\n\nSitemap: http://localhost/sitemap.xml\n")
        ->and(WholeSite::discouraged())->toBeTrue()
        ->and(RobotsTxt::text())->toBe(str_replace("\n\nSitemap: http://localhost/sitemap.xml\n", "\n", $public));
});

it('is stored by the page cache like a page, and goes when Search and social changes (I-464)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $this->folder = cacheOn();

    $miss = addrGet('/robots.txt')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/robots.txt')->assertHeader('X-Teme-Cache', 'HIT')
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent((string) $miss->getContent());

    expect(cacheEntry('/robots.txt')?->tags)->toContain('seo');

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('seo', ['robots_extra_rules' => 'Disallow: /print/'], [], [], 0, (string) $admin->id));

    expect(cacheKept('/robots.txt'))->toBe([])
        ->and((string) addrGet('/robots.txt')->getContent())->toContain("\n\nDisallow: /print/\n");
});
