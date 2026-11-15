<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Fields\BuiltInFields;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * The page's hreflang links, as hreflang => site-relative address.
 *
 * @return array<string, string>
 */
function hlLinks(string $address, int $status = 200): array
{
    $dom = headDom($address, $status);
    $root = rtrim(url('/'), '/');
    $links = [];

    foreach ($dom->query('//head/link[@rel="alternate"][@hreflang][not(@type)]') ?: [] as $link) {
        /** @var DOMElement $link */
        $links[$link->getAttribute('hreflang')] = substr($link->getAttribute('href'), strlen($root));
    }

    return $links;
}

it('writes an hreflang link for each version, the page itself included, and x-default to the default language\'s', function (): void {
    $expected = ['en-GB' => '/hello/', 'fr' => '/fr/bonjour/', 'x-default' => '/hello/'];

    expect(hlLinks('/hello/'))->toBe($expected)
        ->and(hlLinks('/fr/bonjour/'))->toBe($expected);
});

it('writes no hreflang on a page with no other version', function (): void {
    expect(hlLinks('/alone/'))->toBe([]);
});

it('writes no hreflang on a page search engines are told not to list', function (): void {
    $post = Content::query()->findOrFail($this->site->post);
    docSave($post, $this->site->editor, docWith(docText()), [BuiltInFields::NOINDEX => true]);
    listsPublish($post, $this->site->editor);

    expect(hlLinks('/hello/'))->toBe([])
        ->and(hlLinks('/?s=hello'))->toBe([]);
});

it('writes x-default only when the default language\'s version is among them', function (): void {
    Languages::set(['fr', 'es']);
    DB::table('contents')->where('id', $this->site->post)->update(['visibility' => 'private']);

    expect(hlLinks('/fr/bonjour/'))->toBe(['fr' => '/fr/bonjour/', 'es' => '/es/hola/']);
});

it('writes hreflang on page 1 of the home page and never on page 2', function (): void {
    cmSettings(['posts_per_page' => 1], 'reading');
    LanguageSite::version($this->site->editor, 'Howdy', null, null, [], publish: true);

    expect(hlLinks('/'))->toBe(['en-GB' => '/', 'fr' => '/fr/', 'x-default' => '/'])
        ->and(hlLinks('/page/2/'))->toBe([]);
});
