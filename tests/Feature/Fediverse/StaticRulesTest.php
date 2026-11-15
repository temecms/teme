<?php

declare(strict_types=1);

/*
 * A stored page must never answer a fediverse server that asks for a
 * document (docs/plans/fediverse-actors.md, D5): nginx and Caddy serve no
 * static file when Accept asks for activity+json or ld+json, and the
 * operators' guide tells a Cloudflare site to bypass its cache for them.
 */

it('gives nginx no static file when Accept asks for activity or ld+json', function (): void {
    $maps = (string) file_get_contents(base_path('docker/nginx/conf.d/teme-static.conf'));

    expect($maps)->toContain('map $http_accept $teme_static_file {')
        ->and($maps)->toContain('"~*(activity\+json|ld\+json)" "/.teme-none";')
        ->and($maps)->toContain('default $teme_static_page;')
        ->and($maps)->toContain('"GET::0:0"  "${uri}index.html";');
});

it('gives Caddy no static file when Accept asks for activity or ld+json', function (): void {
    $caddy = (string) file_get_contents(base_path('docker/caddy/teme-static.caddy'));

    expect($caddy)->toContain('not header_regexp Accept (?i)(activity\+json|ld\+json)');
});

it('tells a Cloudflare site to bypass its cache for fediverse requests', function (): void {
    $guide = (string) file_get_contents(base_path('docs/guides/operators/cloudflare.md'));

    expect($guide)->toContain('`activity+json`')->and($guide)->toContain('`ld+json`');
});
