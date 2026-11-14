<?php

declare(strict_types=1);

use App\Console\Commands\CheckStaticPages;
use App\Domain\Media\StorageKeys;
use Illuminate\Support\Str;
use Tests\Fixtures\Deployment\ImageFiles;

/*
 * The image's nginx, read as text: the suite starts no nginx. CI's image
 * workflow asks the running server the same questions.
 */

it('turns the image\'s real-IP rewriting off, so PHP sees the connection\'s own address', function (): void {
    expect(ImageFiles::directives('docker/nginx/server-opts.d/remoteip.conf'))->toBe([]);

    foreach (ImageFiles::NGINX_FILES as $path) {
        expect(implode("\n", ImageFiles::directives($path)))->not->toMatch('/\b(set_real_ip_from|real_ip_header|real_ip_recursive)\b/');
    }
});

it('adds the image\'s security headers only where PHP sent none', function (string $header, string $variable, string $value): void {
    $upstream = '$upstream_http_'.str_replace('-', '_', strtolower($header));
    $sent = array_map(fn (string $line): string => (string) preg_replace('/\s+/', ' ', $line), ImageFiles::directives('docker/nginx/server-opts.d/security.conf'));

    expect(ImageFiles::text('docker/nginx/conf.d/teme-edge.conf'))->toContain("map {$upstream} \${$variable} {\n    \"\"      \"{$value}\";\n    default \"\";\n}")
        ->and($sent)->toContain("add_header {$header} \${$variable} always;");
})->with([
    ['X-Frame-Options', 'teme_x_frame_options', 'SAMEORIGIN'],
    ['X-Content-Type-Options', 'teme_x_content_type_options', 'nosniff'],
    ['Referrer-Policy', 'teme_referrer_policy', 'strict-origin-when-cross-origin'],
]);

it('sends no Strict-Transport-Security but the value Teme\'s rule gives', function (): void {
    $lines = [];

    foreach (ImageFiles::NGINX_FILES as $path) {
        foreach (ImageFiles::directives($path) as $line) {
            if (stripos($line, 'strict-transport-security') !== false) {
                $lines[] = (string) preg_replace('/\s+/', ' ', $line);
            }
        }
    }

    expect($lines)->toBe(['add_header Strict-Transport-Security $teme_hsts always;']);
});

it('adds only headers teme:cache:check-static compares between a file and PHP', function (): void {
    $shared = (new ReflectionClassConstant(CheckStaticPages::class, 'SHARED_HEADERS'))->getValue();
    preg_match_all('/^add_header\s+(\S+)/m', ImageFiles::text('docker/nginx/server-opts.d/security.conf'), $found);
    $added = array_map(strtolower(...), $found[1]);

    expect($added)->toEqualCanonicalizing(['x-frame-options', 'x-content-type-options', 'referrer-policy', 'strict-transport-security'])
        ->and(array_diff($added, $shared))->toBe([]);
});

it('caches for a year only addresses that change with their content (I-420)', function (string $path, bool $immutable): void {
    $patterns = [];

    foreach (ImageFiles::locations('docker/nginx/server-opts.d/performance.conf') as $location) {
        if (str_contains($location['body'], 'immutable')) {
            $patterns[] = '#'.Str::after($location['match'], '~ ').'#';
        }
    }

    $matched = array_filter($patterns, fn (string $pattern): bool => preg_match($pattern, $path) === 1);

    expect($patterns)->toHaveCount(2)
        ->and($matched !== [])->toBe($immutable);
})->with([
    'the admin\'s build' => ['/build/assets/app-4f3c2a1b.js', true],
    'a theme\'s build' => ['/themes/default/build/assets/theme-9a8b7c6d.css', true],
    'the build\'s manifest' => ['/build/manifest.json', false],
    'the icon' => ['/favicon.ico', false],
    'a file under media by another name' => ['/storage/media/logo.png', false],
    'a page whose address ends .css' => ['/notes/print.css', false],
]);

it('caches a media variant for a year, at the address Teme gives it', function (): void {
    $prefix = (string) parse_url((string) config('filesystems.disks.media.url'), PHP_URL_PATH);
    $path = $prefix.'/'.StorageKeys::variant((string) Str::ulid(), 3, 'a-cat', 'w640');
    $location = collect(ImageFiles::locations('docker/nginx/server-opts.d/performance.conf'))
        ->first(fn (array $one): bool => str_contains($one['match'], '/storage/media/'));

    expect($prefix)->toBe('/storage/media')
        // The pattern as nginx reads it: quoted in the file, since nginx takes an unquoted { for a block.
        ->and(preg_match('#'.trim(Str::after($location['match'], '~ '), '"').'#', $path))->toBe(1)
        ->and($location['body'])->toContain('add_header Cache-Control "public, max-age=31536000, immutable";');
});

it('compresses text with gzip, and leaves Brotli and zstd to a CDN (I-419)', function (): void {
    $settings = ImageFiles::text('docker/nginx/server-opts.d/performance.conf');
    preg_match('/^gzip_types (.+);$/m', $settings, $types);

    expect($settings)->toContain("\ngzip on;\n", "\ngzip_vary on;\n", "\ngzip_proxied any;\n")
        ->and(explode(' ', $types[1]))->toContain('text/css', 'text/javascript', 'application/javascript', 'application/json', 'application/xml', 'application/rss+xml', 'application/atom+xml', 'image/svg+xml', 'text/xsl');

    foreach (ImageFiles::NGINX_FILES as $path) {
        expect(implode("\n", ImageFiles::directives($path)))->not->toMatch('/\b(brotli|zstd)\b/');
    }
});

it('gives Tools > Backups\' slow requests 15 minutes, and no other request more than the image\'s limit', function (): void {
    $locations = ImageFiles::locations('docker/nginx/server-opts.d/teme-backups.conf');
    $body = $locations[0]['body'];

    expect($locations)->toHaveCount(1)
        ->and($locations[0]['match'])->toBe('^~ /admin/tools/backups/')
        ->and($body)->toContain('fastcgi_read_timeout 900s;')
        // The image's own buffers, which its PHP location reads from NGINX_FASTCGI_BUFFERS and NGINX_FASTCGI_BUFFER_SIZE.
        ->and($body)->toContain('fastcgi_buffers 8 8k;', 'fastcgi_buffer_size 8k;')
        ->and(strpos($body, 'include        fastcgi_params;'))->toBeLessThan(strpos($body, 'SCRIPT_FILENAME $document_root/index.php;'))
        ->and(strpos($body, 'include        fastcgi_params;'))->toBeLessThan(strpos($body, 'SCRIPT_NAME     /index.php;'));

    foreach (ImageFiles::NGINX_FILES as $path) {
        preg_match_all('/fastcgi_read_timeout\s+([^;]+);/', implode("\n", ImageFiles::directives($path)), $found);

        expect(array_diff($found[1], ['900s', '$PHP_MAX_EXECUTION_TIME', '5s']))->toBe([]);
    }
});

it('keeps the server\'s headers in every location that adds one of its own', function (string $path): void {
    $unmerged = array_filter(ImageFiles::locations($path), fn (array $location): bool => preg_match('/^\s*add_header\s/m', $location['body']) === 1
        && ! str_contains($location['body'], 'add_header_inherit merge;'));

    expect($unmerged)->toBe([]);
})->with(ImageFiles::NGINX_FILES);

it('uses, in the files nginx reads as they are, no variable the image would have to fill in', function (string $path): void {
    expect(implode("\n", ImageFiles::directives($path)))->not->toMatch('/\$\{?[A-Z]/');
})->with(array_values(array_filter(ImageFiles::NGINX_FILES, fn (string $path): bool => ! str_ends_with($path, '.template'))));

it('sets no fastcgi_busy_buffers_size, which locations with smaller buffers would inherit and nginx refuse (#312)', function (string $path): void {
    // nginx makes it twice the larger buffer itself, which is 64k in the image's PHP location.
    expect(implode("\n", ImageFiles::directives($path)))->not->toContain('fastcgi_busy_buffers_size');
})->with(ImageFiles::NGINX_FILES);
