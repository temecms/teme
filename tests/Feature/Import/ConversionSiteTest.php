<?php

declare(strict_types=1);

use App\Domain\Import\Convert\ConversionSite;

it('does not treat a staging host as the site’s own page today', function (): void {
    $site = new ConversionSite('https://example.com');

    expect($site->pageOf('https://stage.example.com/a-post/'))->toBeNull();
});
