<?php

declare(strict_types=1);

use App\Domain\Addresses\PathLinkResolver;
use App\Domain\Addresses\PathProjection;
use App\Domain\Api\Items\BodyLinks;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Queries\LinkChoices;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    $this->reuters = Credited::guest('Reuters', 'reuters');
    $this->link = fn (string $href, string $text = 'Ada'): array => ['type' => 'paragraph', 'content' => [[
        'type' => 'text', 'text' => $text,
        'marks' => [['type' => 'link', 'attrs' => ['href' => $href, 'target' => null, 'rel' => null, 'class' => null, 'title' => null]]],
    ]]];
});

it('leads a stored link to an account to its page, as before', function (): void {
    $article = pageArticleFromDocument($this->ada, 'Linked', [($this->link)('teme:user/'.$this->ada->id)]);
    $record = app(RenderRecord::class);
    $record->start(0);

    expect(app(PathLinkResolver::class)->target('user', (string) $this->ada->id))->toBe('/author/'.$this->ada->slug.'/')
        ->and($record->tags())->toContain('person:'.$this->ada->id);

    addrGet('/'.$article->slug.'/')->assertOk()->assertSee('href="/author/'.$this->ada->slug.'/"', false);
});

it('reads an account link in the API\'s body in the same statement as the rest', function (): void {
    $document = ['type' => 'doc', 'content' => [($this->link)('teme:user/'.$this->ada->id)]];
    // The settings the links read are read once per request, before any count.
    BodyLinks::for($document, app(PathLinkResolver::class));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $links = BodyLinks::for($document, app(PathLinkResolver::class));
    $read = count(DB::getQueryLog());

    expect($links->target('user', (string) $this->ada->id))->toBe('/author/'.$this->ada->slug.'/')
        ->and(count(DB::getQueryLog()))->toBe($read)
        ->and($read)->toBe(1);
});

it('stores a pasted address of an account\'s page as a link to the account', function (): void {
    expect(app(PathLinkResolver::class)->targetAt('/author/'.$this->ada->slug.'/'))->toBe(['kind' => 'user', 'id' => (string) $this->ada->id]);
});

it('keeps a pasted address of a guest\'s page as a site path', function (): void {
    expect(app(PathLinkResolver::class)->targetAt('/author/reuters/'))->toBeNull();
});

it('offers an account\'s author page in the editor\'s link choices', function (): void {
    pageArticle($this->ada, 'Published');

    $authors = collect(LinkChoices::search('Ada'))->firstWhere('key', 'authors');

    expect($authors['choices'])->toBe([['href' => 'teme:user/'.$this->ada->id, 'title' => 'Ada Lovelace', 'note' => null]]);
});
