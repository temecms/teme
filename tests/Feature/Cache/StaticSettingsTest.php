<?php

declare(strict_types=1);

use App\Domain\Addresses\Paths;
use App\Domain\Cache\SignedInMarker;
use App\Domain\Cache\StaticPages;

/*
 * The web server's settings for the static-file driver (slice 1.7d″) are
 * text the suite can't run: it starts no nginx and no Caddy. These hold them
 * in step with the code they mirror (D14), so a reserved address, the
 * marker's name, a file's name or the folder can't change on one side
 * only. What only a real server proves, teme:cache:check-static asks it.
 */

it('names in nginx\'s settings the addresses the router keeps, the marker, a file\'s name and the folder (D14)', function (): void {
    $maps = (string) file_get_contents(base_path('docker/nginx/conf.d/teme-static.conf'));
    $site = (string) file_get_contents(base_path('docker/nginx/site-opts.d/http.conf.template'));
    $folder = str_replace('\\', '/', substr((string) config('page_cache.stores.static.path'), strlen(base_path()) + 1));

    expect($folder)->toBe('storage/page-cache/static')
        ->and($maps)->toContain('"~^/('.implode('|', Paths::RESERVED).')(/|$)" 1;')
        ->and($maps)->toContain('"~(^|;\s*)'.SignedInMarker::COOKIE.'=" 1;')
        ->and($maps)->toContain('"GET::0:0"  "${uri}'.StaticPages::INDEX.'";')
        ->and($maps)->toContain('"HEAD::0:0" "${uri}'.StaticPages::INDEX.'";')
        ->and($site)->toContain('root /var/www/html/'.$folder.';')
        ->and($site)->toContain('try_files $teme_static_file @teme_app;')
        ->and($site)->toContain('add_header_inherit merge;')
        ->and($site)->toContain('location @teme_app {');
});

it('names in Caddy\'s settings the same addresses, marker, file\'s name and folder (D14)', function (): void {
    $caddy = (string) file_get_contents(base_path('docker/caddy/teme-static.caddy'));

    expect($caddy)->toContain('not path_regexp ^/('.implode('|', Paths::RESERVED).')(/|$)')
        ->and($caddy)->toContain('not header_regexp Cookie (^|;\s*)'.SignedInMarker::COOKIE.'=')
        ->and($caddy)->toContain('try_files {path}'.StaticPages::INDEX)
        ->and(substr_count($caddy, '/var/www/html/storage/page-cache/static'))->toBe(2);
});

it('uses in nginx\'s template only the variables the image sets', function (): void {
    $site = (string) file_get_contents(base_path('docker/nginx/site-opts.d/http.conf.template'));

    // The image hands envsubst only the PHP_, NGINX_, SSL_, LOG_ and APACHE_
    // variables it has; any other would reach nginx as written, and stop it.
    preg_match_all('/\$\{?((?:PHP|NGINX|SSL|LOG|APACHE)_[A-Z_]+)/', $site, $found);

    expect(array_values(array_unique($found[1])))
        ->toEqualCanonicalizing(['NGINX_HTTP_PORT', 'NGINX_WEBROOT', 'NGINX_FASTCGI_BUFFERS', 'NGINX_FASTCGI_BUFFER_SIZE', 'PHP_MAX_EXECUTION_TIME']);
});
