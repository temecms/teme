<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\PathProjection;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Site\Document\JsonLdNode;
use App\Domain\Site\Document\Precedence;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->hostile = '</script><script>x</script> <!-- '.mb_chr(0x2028).' '.mb_chr(0x2029).' Fish & Chips';
    cmSettings(['site_title' => $this->hostile, 'tagline' => $this->hostile], 'general');
    $this->author = User::factory()->create(['name' => 'Jane '.$this->hostile])->assignRole('author');
    $this->author->forceFill(['bio' => $this->hostile])->save();
    app(PathProjection::class)->syncUser($this->author);
    $picture = pageImage($this->author, 1200, 800);
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($picture->id, (string) $this->author->id, $picture->title, null, $this->hostile, $this->hostile));
    $this->category = headTerm('category', 'News '.$this->hostile, $this->hostile);
    $this->post = headPost($this->author, 'Harbour '.$this->hostile, [BuiltInFields::META_DESCRIPTION => $this->hostile], [$this->category, headTerm('tag', 'Tides '.$this->hostile)], featured: $picture);
    headWith(Precedence::Plugin, [new JsonLdNode(['@id' => 'https://plugin.example/#thing', '@type' => 'Thing', 'name' => $this->hostile])]);
});

it('keeps every node core makes inside its script, whatever its text holds, and gives the same text back (I-698)', function (string $page): void {
    $uri = match ($page) {
        'an article' => Addresses::relativeUrl($this->post),
        'a category' => Addresses::relativeUrl($this->category),
        'an author' => Addresses::relativeUrl($this->author),
        default => '/?s=harbour',
    };
    $html = (string) addrGet((string) $uri)->assertOk()->getContent();
    preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $script);
    $json = $script[1] ?? '';
    $graph = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['@graph'];
    $escaped = fn (int $code): string => trim((string) json_encode(mb_chr($code)), '"');

    expect($json)->not->toContain('<')
        ->and(stripos($json, '</script'))->toBeFalse()
        ->and($json)->toContain($escaped(0x2028))
        ->and($json)->toContain($escaped(0x2029))
        ->and($json)->toContain('Fish & Chips')
        ->and($json)->not->toContain('&amp;')
        ->and(sdNode($graph, 'http://localhost/#website')['name'])->toBe($this->hostile)
        ->and(sdNode($graph, 'https://plugin.example/#thing')['name'])->toBe($this->hostile);
})->with(['an article', 'a category', 'an author', 'a search']);
