<?php

declare(strict_types=1);

use App\Domain\Cache\SignedInMarker;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
    $this->script = fn (string $html): string => preg_match('#<script data-teme="comments">(.+?)</script>#s', $html, $match) === 1 ? $match[1] : '';
});

it('writes core\'s comments script once, after the session script, on a page that draws the form, the same for every reader (D1)', function (): void {
    $reader = (string) addrGet('/harbour-news/')->assertOk()->getContent();
    $signedIn = (string) cacheGet('/harbour-news/', [SignedInMarker::COOKIE => '1'])->getContent();

    expect(substr_count($reader, '<script data-teme="comments">'))->toBe(1)
        ->and(strpos($reader, '<script data-teme="session">'))->toBeLessThan(strpos($reader, '<script data-teme="comments">'))
        ->and(($this->script)($reader))->not->toBe('')->toBe(($this->script)($signedIn))
        ->and(($this->script)($reader))->toContain('"Posting your comment\u2026"')->toContain('\/_teme\/comments\/pending')
        ->toContain('[data-teme-actions]')->toContain('[data-teme-edit]')->toContain('"editNamed":":name')->toContain('s comment"')
        ->toContain('[data-teme-count]')->toContain('[data-teme-empty]')->toContain('"countMany":":count comments"');
});

it('writes the script wherever the default theme draws a thread, form or not: a closed thread with comments carries it; a closed one with none, a page that takes no comments and the line for signed-in people only do not (D3, 1.8b′\'s D1 amended)', function (): void {
    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'closed']);

    expect((string) addrGet('/harbour-news/')->assertOk()->getContent())->not->toContain('Comments are closed.')->not->toContain('data-teme="comments"');

    cmComment($this->item);
    $closed = (string) addrGet('/harbour-news/')->assertOk()->getContent();

    expect($closed)->toContain('Comments are closed.')
        ->and(substr_count($closed, '<script data-teme="comments">'))->toBe(1)
        ->and(dtDom($closed)->query('//form[@data-teme-form]')->length)->toBe(0);

    pgAuthorsPage($this->author, 'About', published: true);

    expect((string) addrGet('/about/')->assertOk()->getContent())->not->toContain('data-teme="comments"');

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'open']);
    cmSettings(['signed_in_only' => true]);

    expect((string) addrGet('/harbour-news/')->getContent())->toContain('Only people signed in to this site can comment here.')->not->toContain('data-teme="comments"');
});

it('writes the script once for a theme that has the thread\'s hooks and no actions line, the form pushing it (D3, D11)', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/hookless')]]);
    themeActivate('hookless');
    $top = cmComment($this->item);
    $html = (string) addrGet('/harbour-news/')->assertOk()->getContent();
    $dom = dtDom($html);

    expect($dom->query('//li[@data-teme-comment="'.$top->id.'"]//a[@data-teme-reply="'.$top->id.'"]')->length)->toBe(1)
        ->and($dom->query('//*[@data-teme-actions]')->length)->toBe(0)
        ->and(substr_count($html, '<script data-teme="comments">'))->toBe(1);
});

it('keeps core\'s two scripts within 5 KB compressed, study Q\'s budget for enhancement (D2, I-253)', function (): void {
    preg_match_all('#<script data-teme="(?:session|comments)">(.+?)</script>#s', (string) addrGet('/harbour-news/')->getContent(), $scripts);

    expect($scripts[1])->toHaveCount(2)
        ->and(strlen((string) gzencode(implode('', $scripts[1]), 9)))->toBeLessThanOrEqual(5 * 1024);
});

it('keeps core\'s three scripts, the regions one too, within the same 5 KB compressed on a page with a width region (I-253)', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/wide')]]);
    themeActivate('wide');
    $admin = User::factory()->create()->assignRole('administrator');
    $region = regionCreate($admin, 'wide', 'half-page');
    scElevated(function () use ($region, $admin): void {
        regionSave($region, $admin, [['type' => 'site-code', 'attrs' => ['id' => (string) Str::ulid(), 'code' => '<div>Ad</div>', 'origins' => []]]]);
        listsPublish($region, $admin);
    });

    preg_match_all('#<script data-teme="(?:session|comments|regions)">(.+?)</script>#s', (string) addrGet('/harbour-news/')->getContent(), $scripts);

    expect($scripts[1])->toHaveCount(3)
        ->and(strlen((string) gzencode(implode('', $scripts[1]), 9)))->toBeLessThanOrEqual(5 * 1024);
});

it('writes the same script on every item, naming none: a stored page shares it (D1, 1.7c\'s D11)', function (): void {
    cmItem($this->author, 'Ferry times');
    $harbour = ($this->script)((string) addrGet('/harbour-news/')->getContent());

    // The script is really drawn: two empty pages would be equal too (the B-349 lesson).
    expect($harbour)->toContain('teme-commenter-key')
        ->toBe(($this->script)((string) addrGet('/ferry-times/')->getContent()))
        ->not->toContain((string) $this->item->id)
        ->not->toContain('harbour-news');
});
