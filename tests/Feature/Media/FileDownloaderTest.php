<?php

declare(strict_types=1);

use App\Domain\Media\Downloads\DownloadedFile;
use App\Domain\Media\Downloads\DownloadRefused;
use App\Domain\Media\Downloads\FileDownloader;
use App\Domain\Media\Downloads\SizeLimit;
use App\Domain\Support\Network\CheckedAddress;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Sleep::fake();

    // A folder of this test's own, so what a refusal leaves behind can be counted.
    $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'teme-downloads-'.Str::random(10);
    mkdir($this->folder);
    app()->when(FileDownloader::class)->needs('$folder')->give($this->folder);

    $this->jpeg = dlJpeg();
    $this->serve = fn (array $answers) => dlServe([
        'photos.example' => ['93.184.216.34'],
        'cdn.example' => ['93.184.216.35', '2606:2800:220:1:248:1893:25c8:1946'],
        'inside.example' => ['10.0.0.5'],
        'sneaky.example' => ['93.184.216.36', '127.0.0.1'],
    ], $answers);
    $this->download = fn (string $address, bool $imagesOnly = false): DownloadedFile => app(FileDownloader::class)->download($address, $imagesOnly);
    $this->refused = function (string $address, bool $imagesOnly = false): DownloadRefused {
        try {
            ($this->download)($address, $imagesOnly);
        } catch (DownloadRefused $refusal) {
            return $refusal;
        }

        throw new RuntimeException("{$address} was downloaded, though the test expects a refusal.");
    };
    $this->leftovers = fn (): array => glob($this->folder.DIRECTORY_SEPARATOR.'*') ?: [];
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->folder.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($this->folder);
});

it('asks the old site again when it is busy or silent, and says yes when a later try answers', function (string $first): void {
    ($this->serve)(['photos.example/*' => $first === 'busy'
        ? Http::sequence()->pushStatus(503)->pushStatus(200)
        : Http::sequence()->pushFailedConnection('timeout')->pushStatus(200)]);

    expect(app(FileDownloader::class)->answers('https://photos.example/'))->toBeTrue();

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(1)->second()]);
    expect(($this->leftovers)())->toBe([]);
})->with(['busy' => ['busy'], 'silent' => ['silent']]);

it('says the old site does not answer only after three tries', function (): void {
    ($this->serve)(['photos.example/*' => Http::sequence()->pushStatus(503)->pushStatus(503)->pushFailedConnection('timeout')]);

    expect(app(FileDownloader::class)->answers('https://photos.example/'))->toBeFalse();

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(2)->seconds()]);
    expect(($this->leftovers)())->toBe([]);
});

it('counts a refused HEAD as an answer, and never asks again a host it will not connect to', function (): void {
    ($this->serve)(['photos.example/*' => Http::response('', 405)]);
    expect(app(FileDownloader::class)->answers('https://photos.example/'))->toBeTrue();
    Http::assertSentCount(1);

    ($this->serve)([]);
    expect(app(FileDownloader::class)->answers('https://inside.example/'))->toBeFalse();
    Http::assertNothingSent();
    Sleep::assertNeverSlept();
});

it('downloads a file into a temporary file, judged by its bytes, named from its address, with where it came from (I-453)', function (): void {
    ($this->serve)(['photos.example/*' => Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg'])]);

    $file = ($this->download)('https://Photos.Example/2026/harbour%20at%20dawn.jpg?ver=3&token=abc#top');

    expect($file->kind)->toBe('image')
        ->and($file->mimeType)->toBe('image/jpeg')
        ->and($file->name)->toBe('harbour at dawn.jpg')
        ->and($file->sizeBytes)->toBe(strlen($this->jpeg))
        ->and($file->sourceUrl)->toBe('https://photos.example/2026/harbour%20at%20dawn.jpg')
        ->and(file_get_contents($file->path))->toBe($this->jpeg)
        ->and(dirname($file->path))->toBe($this->folder);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'Teme'));
});

it('asks for the bytes as sent, through no proxy, pinned to the checked address, following no redirect itself (I-453)', function (): void {
    $limit = new SizeLimit(1024);
    $options = FileDownloader::options(new CheckedAddress('https', 'photos.example', 443, '93.184.216.34'), $limit, '/tmp/teme-download-x');

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['decode_content'])->toBeFalse()
        ->and($options['protocols'])->toBe(['http', 'https'])
        ->and($options['progress'])->toBe($limit)
        ->and($options['sink'])->toBe('/tmp/teme-download-x')
        ->and($options['curl'])->toBe([CURLOPT_RESOLVE => ['photos.example:443:93.184.216.34']])
        ->and(FileDownloader::options(new CheckedAddress('http', 'photos.example', 80, '93.184.216.34'), $limit, '/tmp/x')['curl'])->toBe([CURLOPT_RESOLVE => ['photos.example:80:93.184.216.34']])
        ->and(FileDownloader::options(new CheckedAddress('https', 'cdn.example', 443, '2606:2800:220:1:248:1893:25c8:1946'), $limit, '/tmp/x')['curl'])->toBe([CURLOPT_RESOLVE => ['cdn.example:443:[2606:2800:220:1:248:1893:25c8:1946]']])
        ->and(FileDownloader::options(new CheckedAddress('https', '', 443, '93.184.216.34'), $limit, '/tmp/x'))->not->toHaveKey('curl');
});

it('downloads over plain http from a public host, as I-453 allows', function (): void {
    ($this->serve)(['photos.example/*' => Http::response($this->jpeg, 200)]);

    expect(($this->download)('http://photos.example/old/boats.jpg')->sourceUrl)->toBe('http://photos.example/old/boats.jpg');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://photos.example/old/boats.jpg'));
});

it('refuses an address that isn\'t http or https, carries a password, or writes its host in a form curl reads another way, before asking anyone', function (string $address): void {
    ($this->serve)([]);

    $refusal = ($this->refused)($address);

    expect($refusal->reason)->toBe(DownloadRefused::ADDRESS)
        ->and($refusal->retryable)->toBeFalse()
        ->and($refusal->sentence())->toBe('Teme can download only from an address that starts with http:// or https://, with no user name or password in it.')
        ->and(($this->leftovers)())->toBe([]);

    Http::assertNothingSent();
})->with([
    'ftp' => ['ftp://photos.example/harbour.jpg'],
    'a file on the server' => ['file:///etc/passwd'],
    'an image inside the address' => ['data:image/png;base64,iVBORw0KGgo='],
    'no scheme' => ['photos.example/harbour.jpg'],
    'a user name and password' => ['https://ada:secret@photos.example/harbour.jpg'],
    'a decimal address' => ['http://2130706433/harbour.jpg'],
    'a host ending in a dot' => ['https://photos.example./harbour.jpg'],
    'no host' => ['https:///harbour.jpg'],
]);

it('refuses a host on a private network, whichever of its addresses is private, before connecting (I-453)', function (string $address, string $words): void {
    ($this->serve)([]);

    $refusal = ($this->refused)($address);

    expect($refusal->reason)->toBe(DownloadRefused::NOT_PUBLIC)
        ->and($refusal->retryable)->toBeFalse()
        ->and($refusal->sentence())->toBe($words);

    Http::assertNothingSent();
})->with([
    'a private network' => ['https://inside.example/a.jpg', 'inside.example is on a private network, so Teme won’t connect to it.'],
    'one private address among public ones' => ['https://sneaky.example/a.jpg', 'sneaky.example is on a private network, so Teme won’t connect to it.'],
    'a cloud\'s metadata address, over http' => ['http://169.254.169.254/latest/meta-data/', '169.254.169.254 is on a private network, so Teme won’t connect to it.'],
    'IPv6 loopback, over http' => ['http://[::1]/a.jpg', '[::1] is on a private network, so Teme won’t connect to it.'],
    'loopback, IPv4-mapped' => ['https://[::ffff:127.0.0.1]/a.jpg', '[::ffff:127.0.0.1] is on a private network, so Teme won’t connect to it.'],
]);

it('refuses a name it can\'t find, in the address check\'s words', function (): void {
    ($this->serve)([]);

    $refusal = ($this->refused)('https://nowhere.example/a.jpg');

    expect($refusal->reason)->toBe(DownloadRefused::UNKNOWN_HOST)
        ->and($refusal->sentence())->toBe('Teme couldn’t find nowhere.example. Check the address.');

    Http::assertNothingSent();
});

it('checks every redirect again, and never connects to a private address it is sent to (I-453)', function (): void {
    ($this->serve)([
        'photos.example/*' => Http::response('', 302, ['Location' => 'https://inside.example/a.jpg']),
        'inside.example/*' => Http::response($this->jpeg, 200),
    ]);

    $refusal = ($this->refused)('https://photos.example/a.jpg');

    expect($refusal->reason)->toBe(DownloadRefused::NOT_PUBLIC)
        ->and($refusal->sentence())->toBe('inside.example is on a private network, so Teme won’t connect to it.')
        ->and(($this->leftovers)())->toBe([]);

    Http::assertSentCount(1);
});

it('follows redirects by path, by host and relative to where it is, keeping the address it was given as the source', function (): void {
    ($this->serve)([
        'photos.example/a.jpg' => Http::response('', 301, ['Location' => '/2026/b.jpg']),
        'photos.example/2026/b.jpg' => Http::response('', 302, ['Location' => '//cdn.example/files/c.jpg']),
        'cdn.example/files/c.jpg' => Http::response('', 307, ['Location' => 'd.jpg']),
        'cdn.example/files/d.jpg' => Http::response($this->jpeg, 200),
    ]);

    $file = ($this->download)('https://photos.example/a.jpg');

    expect($file->sourceUrl)->toBe('https://photos.example/a.jpg')
        ->and($file->name)->toBe('a.jpg')
        ->and($file->kind)->toBe('image');

    Http::assertSentCount(4);
});

it('follows five redirects at most', function (): void {
    ($this->serve)(['photos.example/*' => fn () => Http::response('', 302, ['Location' => '/again.jpg'])]);

    $refusal = ($this->refused)('https://photos.example/a.jpg');

    expect($refusal->reason)->toBe(DownloadRefused::REDIRECTS)
        ->and($refusal->sentence())->toBe('The address sent Teme on more than five times, so Teme stopped.');

    Http::assertSentCount(6);
});

it('refuses an answer that isn\'t the file, in words for its status, without trying again', function (string $status, string $reason, string $words): void {
    ($this->serve)(['photos.example/*' => Http::response('Not here', (int) $status)]);

    $refusal = ($this->refused)('https://photos.example/a.jpg');

    expect($refusal->reason)->toBe($reason)
        ->and($refusal->retryable)->toBeFalse()
        ->and($refusal->sentence())->toBe($words)
        ->and(($this->leftovers)())->toBe([]);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    'not found' => ['404', DownloadRefused::GONE, 'photos.example has no file at this address (404).'],
    'gone' => ['410', DownloadRefused::GONE, 'photos.example has no file at this address (410).'],
    'forbidden' => ['403', DownloadRefused::FORBIDDEN, 'photos.example won’t let Teme download this file (403).'],
    'a bad request' => ['400', DownloadRefused::STATUS, 'photos.example answered 400 instead of sending the file.'],
    'a redirect with nowhere to go' => ['302', DownloadRefused::STATUS, 'photos.example answered 302 instead of sending the file.'],
]);

it('tries a busy or unreachable host three times, waiting a second, then three, then says so (I-453)', function (string $failure): void {
    ($this->serve)(['photos.example/*' => $failure === 'busy' ? fn () => Http::response('', 503) : Http::failedConnection()]);

    $refusal = ($this->refused)('https://photos.example/a.jpg');

    expect($refusal->retryable)->toBeTrue()
        ->and($refusal->sentence())->toBe($failure === 'busy'
            ? 'photos.example is busy or had an error (503). Try again later.'
            : 'Teme couldn’t reach photos.example, or it took too long to answer. Try again later.');

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(3)->seconds()]);
})->with([
    'busy' => ['busy'],
    'unreachable' => ['unreachable'],
]);

it('gets the file on a later try when the first fails', function (): void {
    ($this->serve)(['photos.example/*' => Http::sequence()->pushStatus(503)->push($this->jpeg, 200)]);

    expect(($this->download)('https://photos.example/a.jpg')->kind)->toBe('image');

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(1)->second()]);
});

it('refuses a file over the library\'s size limit, or an empty one, leaving nothing behind', function (string $case): void {
    dlMediaLimits(['max_upload_mb' => 1]);
    ($this->serve)(['photos.example/*' => Http::response($case === 'too large' ? str_repeat('a', 1024 * 1024 + 1) : '', 200)]);

    $refusal = ($this->refused)('https://photos.example/a.jpg');

    expect($refusal->reason)->toBe($case === 'too large' ? DownloadRefused::TOO_LARGE : DownloadRefused::EMPTY)
        ->and($refusal->sentence())->toBe($case === 'too large' ? 'The file is larger than the 1 MB limit.' : 'The file is empty.')
        ->and(($this->leftovers)())->toBe([]);
})->with([
    'too large' => ['too large'],
    'empty' => ['empty'],
]);

it('stops a transfer once the announced size or the bytes received pass the limit (I-453)', function (): void {
    $limit = new SizeLimit(100);

    expect($limit(0, 0))->toBeFalse()
        ->and($limit(100, 100))->toBeFalse()
        ->and($limit->exceeded)->toBeFalse()
        ->and($limit(0, 101))->toBeTrue()
        ->and($limit->exceeded)->toBeTrue()
        ->and((new SizeLimit(100))(5000, 0))->toBeTrue();
});

it('takes a file its bytes say the library accepts, whatever its name or the type it was sent as', function (string $case): void {
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    ($this->serve)([
        'photos.example/chart.jpg' => Http::response($png, 200, ['Content-Type' => 'text/html']),
        'photos.example/report.pdf' => Http::response((string) file_get_contents(mediaPdf()), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $file = $case === 'a PNG named .jpg, sent as a web page'
        ? ($this->download)('https://photos.example/chart.jpg', imagesOnly: true)
        : ($this->download)('https://photos.example/report.pdf');

    expect([$file->mimeType, $file->kind])->toBe($case === 'a PNG named .jpg, sent as a web page' ? ['image/png', 'image'] : ['application/pdf', 'document']);
})->with([
    'a PNG named .jpg, sent as a web page' => ['a PNG named .jpg, sent as a web page'],
    'a PDF sent as a JPEG' => ['a PDF sent as a JPEG'],
]);

it('refuses a file its bytes say the library doesn\'t accept, or that isn\'t an image when only images will do', function (string $case, string $reason, string $words): void {
    ($this->serve)([
        'photos.example/page.jpg' => Http::response('<!DOCTYPE html><html><head><title>Not found</title></head><body><p>Sorry.</p></body></html>', 200, ['Content-Type' => 'image/jpeg']),
        'photos.example/logo.svg' => Http::response('<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>', 200, ['Content-Type' => 'image/svg+xml']),
        'photos.example/report.pdf' => Http::response((string) file_get_contents(mediaPdf()), 200),
    ]);

    $address = match ($case) {
        'a web page named .jpg' => 'https://photos.example/page.jpg',
        'an SVG' => 'https://photos.example/logo.svg',
        default => 'https://photos.example/report.pdf',
    };
    $refusal = ($this->refused)($address, imagesOnly: true);

    expect($refusal->reason)->toBe($reason)
        ->and($refusal->sentence())->toBe($words)
        ->and(($this->leftovers)())->toBe([]);
})->with([
    'a web page named .jpg' => ['a web page named .jpg', DownloadRefused::WEB_PAGE, 'This address leads to a web page, not a file.'],
    'an SVG' => ['an SVG', DownloadRefused::NOT_ACCEPTED, 'This file isn’t a type the media library accepts.'],
    'a PDF, when only images will do' => ['a PDF, when only images will do', DownloadRefused::NOT_IMAGE, 'This file isn’t an image.'],
]);

it('names the file from its address, safely', function (string $path, string $name): void {
    ($this->serve)(['photos.example/*' => Http::response($this->jpeg, 200)]);

    expect(($this->download)('https://photos.example'.$path)->name)->toBe($name);
})->with([
    'a zero byte and a broken character' => ['/bad%00na%FFme.jpg', 'badna?me.jpg'],
    'a slash inside the name' => ['/a%2Fb.jpg', 'a-b.jpg'],
    'no name at all' => ['/', 'download'],
    'a very long name' => ['/'.str_repeat('a', 300).'.jpg', str_repeat('a', 255)],
    'a long name in another script' => ['/'.str_repeat('%C3%A9', 200).'.jpg', str_repeat('é', 127)],
]);

it('releases its temporary file once, however often it is asked', function (): void {
    ($this->serve)(['photos.example/*' => Http::response($this->jpeg, 200)]);

    $file = ($this->download)('https://photos.example/a.jpg');
    $file->release();
    $file->release();

    expect(file_exists($file->path))->toBeFalse()
        ->and(($this->leftovers)())->toBe([]);
});

it('says an untrusted certificate is the other site’s to fix, tries it once, never over plain http, and leaves the file to the next import (I-453)', function (string $error): void {
    ($this->serve)(['photos.example/*' => Http::failedConnection($error)]);

    $refusal = ($this->refused)('https://photos.example/a.jpg');

    expect($refusal->reason)->toBe(DownloadRefused::CERTIFICATE)
        ->and($refusal->retryable)->toBeTrue()
        ->and($refusal->sentence())->toBe('Teme couldn’t download it securely: photos.example’s HTTPS certificate isn’t trusted (expired, self-signed or for another name). Fix the certificate on that site, then try again.')
        ->and(($this->leftovers)())->toBe([]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://'));
    Sleep::assertNeverSlept();
})->with([
    'self-signed' => ['cURL error 60: SSL certificate problem: self-signed certificate (see https://curl.se/libcurl/c/libcurl-errors.html) for https://photos.example/a.jpg'],
    'expired' => ['cURL error 60: SSL certificate problem: certificate has expired (see https://curl.se/libcurl/c/libcurl-errors.html) for https://photos.example/a.jpg'],
    'for another name' => ['cURL error 60: SSL: no alternative certificate subject name matches target host name \'photos.example\' (see https://curl.se/libcurl/c/libcurl-errors.html) for https://photos.example/a.jpg'],
    'refused by an older libcurl' => ['cURL error 51: SSL: certificate verification failed (see https://curl.se/libcurl/c/libcurl-errors.html) for https://photos.example/a.jpg'],
]);

it('still says a failed handshake or a refused connection couldn’t reach the host, trying three times', function (string $error): void {
    ($this->serve)(['photos.example/*' => Http::failedConnection($error)]);

    $refusal = ($this->refused)('https://photos.example/a.jpg');

    expect($refusal->reason)->toBe(DownloadRefused::UNREACHABLE)
        ->and($refusal->sentence())->toBe('Teme couldn’t reach photos.example, or it took too long to answer. Try again later.');

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(3)->seconds()]);
})->with([
    'a handshake that failed' => ['cURL error 35: OpenSSL SSL_connect: Connection reset by peer (see https://curl.se/libcurl/c/libcurl-errors.html) for https://photos.example/a.jpg'],
    'a refused connection' => ['cURL error 7: Failed to connect to photos.example port 443 (see https://curl.se/libcurl/c/libcurl-errors.html) for https://photos.example/a.jpg'],
]);
