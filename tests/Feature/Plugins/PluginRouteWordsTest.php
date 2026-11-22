<?php

declare(strict_types=1);

use App\Domain\Plugins\PluginWords;

it('says a stored public route in words, keeping the address and its condition', function (string $line, string $words): void {
    expect(PluginWords::route($line))->toBe($words);
})->with([
    ['POST /temecms-site/submit-package', 'Answers a form sent to /temecms-site/submit-package'],
    ['GET /inbound/ping', 'Shows a page at /inbound/ping'],
    ['PUT /inbound/ping', 'Answers PUT requests at /inbound/ping'],
    ['GET / (query author: ^[0-9]+$)', 'Shows a page at / (query author: ^[0-9]+$)'],
    ['not a route line', 'not a route line'],
]);
