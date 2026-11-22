<?php

declare(strict_types=1);

use App\Domain\Import\File\WxrRefused;
use App\Domain\Import\File\WxrStream;
use Illuminate\Support\Facades\Process;

/**
 * The path of one of the file reader's fixtures.
 */
function wxrFixture(string $name): string
{
    return dirname(__DIR__, 3).'/Fixtures/Import/file/'.$name;
}

/**
 * The sentence a file is refused with, or null when it is read whole.
 */
function wxrRefusal(string $path): ?string
{
    try {
        foreach (WxrStream::open($path)->items() as $item) {
            //
        }
    } catch (WxrRefused $refusal) {
        return $refusal->sentence;
    }

    return null;
}

it('reads the channel and each item of an export file of every version', function (string $version): void {
    $stream = WxrStream::open(wxrFixture("wxr-{$version}.xml"));
    $channel = $stream->channel();
    $items = iterator_to_array($stream->items());

    expect($channel['version'])->toBe($version)
        ->and($channel['link'])->toBe('https://old.example')
        ->and($channel['baseBlogUrl'])->toBe('https://old.example')
        ->and($channel['pubDate'])->toBe('Thu, 01 Oct 2026 09:30:00 +0000')
        ->and($channel['authors'])->toHaveCount($version === '1.0' ? 0 : 1)
        ->and($channel['terms'])->toHaveCount(1)
        ->and($channel['terms'][0]['element'])->toBe('category')
        ->and($channel['terms'][0]['fields']['category_nicename'])->toBe('news')
        ->and(array_keys($items))->toBe([0, 1])
        ->and($items[0]['fields']['wp:post_id'])->toBe('10')
        ->and($items[0]['fields']['title'])->toBe('First light')
        ->and($items[0]['fields']['content:encoded'])->toBe('<p>Boats &amp; gulls.</p>')
        ->and($items[0]['fields']['dc:creator'])->toBe('ada')
        ->and($items[0]['fields']['excerpt:encoded'])->toBe('')
        ->and($items[0]['taxonomies'])->toBe([['domain' => 'category', 'nicename' => 'news', 'name' => 'News']])
        ->and($items[1]['meta'])->toBe([['key' => '_wp_page_template', 'value' => 'default']]);

    if ($version !== '1.0') {
        expect($channel['authors'][0])->toMatchArray(['author_id' => '3', 'author_login' => 'ada', 'author_email' => 'ada@old.example']);
    }
})->with(['1.0', '1.1', '1.2']);

it('refuses a file with a document type declaration before reading anything', function (): void {
    expect(wxrRefusal(wxrFixture('doctype.xml')))
        ->toBe('This file has a document type declaration, which an export file never has. Teme doesn’t read it.');
});

it('refuses an entity it would have to look up', function (): void {
    expect(wxrRefusal(wxrFixture('entity.xml')))->toBe('That isn’t a WordPress export file.');
});

it('refuses an RSS feed that isn’t an export', function (): void {
    expect(wxrRefusal(wxrFixture('rss-feed.xml')))->toBe('That isn’t a WordPress export file.');
});

it('refuses a file that isn’t XML at all, and one that breaks off partway', function (): void {
    $folder = sys_get_temp_dir().'/wxr-'.bin2hex(random_bytes(4));
    mkdir($folder);
    file_put_contents("{$folder}/text.xml", 'Just some words.');
    file_put_contents("{$folder}/cut.xml", substr((string) file_get_contents(wxrFixture('wxr-1.2.xml')), 0, -400));

    expect(wxrRefusal("{$folder}/text.xml"))->toBe('That isn’t a WordPress export file.')
        ->and(wxrRefusal("{$folder}/cut.xml"))->toBe('That isn’t a WordPress export file.');
});

it('reads a resumed file from the item after the ones it already read', function (): void {
    $items = iterator_to_array(WxrStream::open(wxrFixture('wxr-1.2.xml'))->items(1));

    expect(array_keys($items))->toBe([1])
        ->and($items[1]['fields']['wp:post_id'])->toBe('11');
});

it('reads fifty thousand items in flat memory', function (): void {
    $path = sys_get_temp_dir().'/wxr-large-'.bin2hex(random_bytes(4)).'.xml';
    $file = fopen($path, 'wb');
    fwrite($file, '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><link>https://old.example</link>');

    for ($id = 1; $id <= 50_000; $id++) {
        fwrite($file, "<item><title>Post {$id}</title><content:encoded><![CDATA[<p>".str_repeat('Words in a paragraph. ', 40)."</p>]]></content:encoded><wp:post_id>{$id}</wp:post_id><wp:post_type>post</wp:post_type><wp:postmeta><wp:meta_key>k</wp:meta_key><wp:meta_value>v</wp:meta_value></wp:postmeta></item>\n");
    }

    fwrite($file, '</channel></rss>');
    fclose($file);

    // In a process of its own, so the peak is the reader's and not the test suite's.
    $script = sys_get_temp_dir().'/wxr-large-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($script, '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; $read = 0; $last = null; foreach (App\\Domain\\Import\\File\\WxrStream::open($argv[1])->items() as $item) { $read++; $last = $item["fields"]["wp:post_id"]; } echo json_encode([$read, $last, memory_get_peak_usage(true)]);');
    $answer = Process::timeout(180)->run([PHP_BINARY, '-d', 'memory_limit=64M', $script, $path]);
    unlink($path);
    unlink($script);

    [$read, $last, $peak] = json_decode($answer->output(), true);

    expect($answer->successful())->toBeTrue()
        ->and($read)->toBe(50_000)
        ->and($last)->toBe('50000')
        ->and($peak)->toBeLessThan(64 * 1024 * 1024);
});
