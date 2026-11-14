<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes the settings reference from the registry', function (): void {
    $path = storage_path('framework/testing/settings-reference.md');

    $this->artisan('teme:docs:settings', ['--output' => $path])->assertSuccessful();

    $markdown = file_get_contents($path);

    expect($markdown)->toContain('# Settings reference')
        ->and($markdown)->toContain('`general.site_title`')
        ->and($markdown)->toContain('The name of the site, shown in the admin')
        ->and($markdown)->toContain('`settings.general.manage`')
        ->and($markdown)->toContain('0.1.0');
});
