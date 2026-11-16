<?php

declare(strict_types=1);

use Tests\Fixtures\Deployment\ImageFiles;

/*
 * How nginx serves an installed theme's files, read as data: the suite
 * starts no nginx.
 */

/** @return array<string, string> each location's body by what it matches */
function themeLocations(): array
{
    return array_column(ImageFiles::locations('docker/nginx/site-opts.d/http.conf.template'), 'body', 'match');
}

it('serves the image’s own theme files first, then the current release’s, and never PHP from either', function (): void {
    $locations = themeLocations();

    expect($locations['^~ /themes/'])->toContain('location ~ \.php$ { return 404; }')
        ->and($locations['^~ /themes/'])->toContain('try_files $uri @teme_extension_themes;')
        ->and($locations['@teme_extension_themes'])->toContain('root /var/teme/extensions/current/public;');
});

it('follows no link from a theme’s files to a file the installer doesn’t own', function (): void {
    expect(themeLocations()['@teme_extension_themes'])->toContain('disable_symlinks if_not_owner from=/var/teme/extensions/current/public;');
});
