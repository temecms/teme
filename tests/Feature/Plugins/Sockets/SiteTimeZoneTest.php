<?php

declare(strict_types=1);

use App\Domain\Plugins\Seams\PluginServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Site\SiteInfo;
use Tests\Fixtures\Backups\ScreenRecords;

uses(RefreshDatabase::class);

it('gives the site’s time zone from Settings › General', function (): void {
    ScreenRecords::settings(['general' => ['timezone' => 'Africa/Lagos']]);

    $site = app(PluginServices::class)->for('tests/hub', 'hub', [], [])[SiteInfo::class];

    expect($site->timeZone())->toBe('Africa/Lagos');
});
