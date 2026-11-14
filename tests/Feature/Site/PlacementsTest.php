<?php

declare(strict_types=1);

use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Fragment;
use App\Domain\Site\Document\Placement;
use App\Domain\Site\Document\Placements;
use App\Domain\Site\Document\Precedence;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    cmItem(User::factory()->create(['name' => 'Jane Doe'])->assignRole('author'), 'Harbour news');
});

it('draws each placement\'s fragments as written, where core owns them: the head\'s last in the head, body start\'s after the skip link, body end\'s after the template and before core\'s script (I-228)', function (): void {
    placeWith(Precedence::Site, Placement::Head, [new Fragment('analytics', '<script data-test="head">window.a = 1 && 2;</script>')]);
    placeWith(Precedence::Site, Placement::BodyStart, [new Fragment('tag-manager', '<noscript data-test="start"><iframe src="https://example.test/ns" onload="x()"></iframe></noscript>')]);
    placeWith(Precedence::Site, Placement::BodyEnd, [new Fragment('chat', '<div data-test="end"></div>')]);

    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();
    $at = fn (string $needle): int => (int) strpos($html, $needle);

    expect($html)->toContain('<script data-test="head">window.a = 1 && 2;</script>')
        ->toContain('<noscript data-test="start"><iframe src="https://example.test/ns" onload="x()"></iframe></noscript>')
        ->and(substr_count($html, 'data-test="head"'))->toBe(1)
        ->and($at('data-test="head"'))->toBeGreaterThan($at('<style>@layer'))->toBeLessThan($at('</head>'))
        ->and($at('data-test="start"'))->toBeGreaterThan($at('class="teme-skip-link"'))->toBeLessThan($at('<main'))
        ->and($at('data-test="end"'))->toBeGreaterThan($at('</main>'))->toBeLessThan($at('data-teme="session"'));
});

it('keeps the first fragment for a key, reading plugin, then site, then core, the rest in the order they came', function (): void {
    placeWith(Precedence::Core, Placement::BodyEnd, [new Fragment('chat', '<div data-test="core-chat"></div>')]);
    placeWith(Precedence::Site, Placement::BodyEnd, [new Fragment('chat', '<div data-test="site-chat"></div>'), new Fragment('survey', '<div data-test="survey"></div>')]);

    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();

    expect($html)->toContain('data-test="site-chat"')->toContain('data-test="survey"')->not->toContain('data-test="core-chat"')
        ->and((int) strpos($html, 'data-test="site-chat"'))->toBeLessThan((int) strpos($html, 'data-test="survey"'));
});

it('refuses a fragment key that isn\'t one, and a contributor registered under the wrong tag', function (): void {
    app()->instance('test.placement.wrong', new stdClass);
    app()->tag(['test.placement.wrong'], Placements::CONTRIBUTORS);

    expect(fn () => new Fragment('Site Code', '<p>Hi</p>'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(Placements::class)->for(Placement::Head, new DocumentPage(null, null)))
        ->toThrow(UnexpectedValueException::class, 'Registered as a PlacementContributor');
});
