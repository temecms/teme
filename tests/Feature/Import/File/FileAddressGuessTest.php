<?php

declare(strict_types=1);

use App\Domain\Import\File\FileAddresses;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * The fixture file with more posts and channel terms added: each post as
 * [id, slug, status, link, its language's slug or null].
 *
 * @param  list<array{0: int, 1: string, 2: string, 3: string, 4: string|null}>  $posts
 * @param  list<string>  $terms  wp:term elements
 */
function fagFile(array $posts, array $terms = []): string
{
    $items = '';

    foreach ($posts as [$id, $slug, $status, $link, $language]) {
        $items .= "\n\t<item>\n\t\t<title><![CDATA[Post {$id}]]></title>\n\t\t<link>{$link}</link>\n\t\t<dc:creator><![CDATA[harbour]]></dc:creator>\n"
            ."\t\t<content:encoded><![CDATA[<p>Words.</p>]]></content:encoded>\n\t\t<excerpt:encoded><![CDATA[]]></excerpt:encoded>\n"
            ."\t\t<wp:post_id>{$id}</wp:post_id>\n\t\t<wp:post_date><![CDATA[2024-03-02 10:00:00]]></wp:post_date>\n\t\t<wp:post_date_gmt><![CDATA[2024-03-02 10:00:00]]></wp:post_date_gmt>\n"
            ."\t\t<wp:post_name><![CDATA[".($status === 'draft' ? '' : $slug)."]]></wp:post_name>\n\t\t<wp:status><![CDATA[{$status}]]></wp:status>\n\t\t<wp:post_parent>0</wp:post_parent>\n\t\t<wp:post_type><![CDATA[post]]></wp:post_type>\n"
            .($language === null ? '' : "\t\t<category domain=\"language\" nicename=\"{$language}\"><![CDATA[{$language}]]></category>\n")
            ."\t</item>";
    }

    $bytes = (string) file_get_contents(ExportFiles::path('companion-site.xml'));
    $bytes = (string) preg_replace('~(\s*<generator>)~', implode('', array_map(fn (string $term): string => "\n\t{$term}", $terms)).'$1', $bytes, 1);

    return (string) preg_replace('~(\s*</channel>)~', $items.'$1', $bytes, 1);
}

/**
 * A Polylang language as the export file's channel holds it.
 */
function fagLanguage(int $id, string $slug): string
{
    return "<wp:term><wp:term_id>{$id}</wp:term_id><wp:term_taxonomy>language</wp:term_taxonomy><wp:term_slug><![CDATA[{$slug}]]></wp:term_slug><wp:term_parent><![CDATA[]]></wp:term_parent><wp:term_name><![CDATA[{$slug}]]></wp:term_name></wp:term>";
}

it('guesses the old site’s addresses from its published posts, never from a draft’s ?p= link', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin, fagFile([
        [301, 'spring-tides', 'publish', 'https://old.example/spring-tides/', null],
        [302, 'draft-one', 'draft', 'https://old.example/?p=302', null],
        [303, 'draft-two', 'draft', 'https://old.example/?p=303', null],
        [304, 'pending-one', 'pending', 'https://old.example/?p=304', null],
    ]));

    expect(FileAddresses::structure($file->id))->toBe('/%postname%/');
});

it('reads a published post’s address after its language’s prefix, as Polylang gives it', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin, fagFile([
        [301, 'spring-tides', 'publish', 'https://old.example/spring-tides/', 'en'],
        [302, 'jwar-bhata', 'publish', 'https://old.example/hi/jwar-bhata/', 'hi'],
        [303, 'marees', 'publish', 'https://old.example/fr/marees/', 'fr'],
    ], [fagLanguage(901, 'en'), fagLanguage(902, 'hi'), fagLanguage(903, 'fr')]));

    expect(FileAddresses::structure($file->id))->toBe('/%postname%/');
});

it('still finds no structure when published posts follow none of WordPress’s own', function (): void {
    $file = FileImports::ready('companion-site.xml', $this->admin, fagFile([
        [301, 'spring-tides', 'publish', 'https://old.example/news/item/spring-tides/', null],
    ]));

    expect(FileAddresses::structure($file->id))->toBe('');
});
