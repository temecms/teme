<?php

declare(strict_types=1);

use App\Domain\Redirects\Queries\SiteAnswers;
use App\Domain\Redirects\Queries\TrashedAddress;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('gives the address a trashed item last had, and the redirect from it, for the offer after trashing (I-856)', function (): void {
    $item = addrPublished($this->editor, 'Harbour news');
    $offers = app(TrashedAddress::class);

    expect($offers->of($item->id))->toBeNull();

    pageTrash($item, $this->editor);
    expect($offers->of($item->id))->toBe(['address' => '/harbour-news/', 'redirectId' => null]);

    $id = rdSaved('/harbour-news/', '/news/');
    expect($offers->of($item->id))->toBe(['address' => '/harbour-news/', 'redirectId' => $id])
        ->and($offers->of('01jvm0000000000000000000aa'))->toBeNull();

    addrGet('/harbour-news/')->assertStatus(301);
});

it('answers in-process what the site would at an address, with no HTTP: a page, an old address, a redirect, a 410, or not found', function (): void {
    $item = addrPublished($this->editor, 'Ferry fares');
    addrRename($item, 'ferry-fares-2026', $this->editor);
    rdSaved('/old-fares', item: $item->id);
    rdSaved('/withdrawn', status: 410);
    $site = app(SiteAnswers::class);
    $answer = function (string $address) use ($site): array {
        $found = $site->at($address);

        return [$found->status, $found->location, $found->by];
    };

    expect($answer('/ferry-fares-2026/'))->toBe([200, null, 'page'])
        ->and($answer('http://localhost/ferry-fares/'))->toBe([301, 'http://localhost/ferry-fares-2026/', 'address'])
        ->and($answer('/old-fares?utm_source=print'))->toBe([301, 'http://localhost/ferry-fares-2026/?utm_source=print', 'redirect'])
        ->and($answer('/withdrawn/'))->toBe([410, null, 'redirect'])
        ->and($answer('/never-was/'))->toBe([404, null, 'not found'])
        ->and(fn () => $site->at('https://elsewhere.example/'))->toThrow(InvalidArgumentException::class, 'Not an address on this site: [https://elsewhere.example/].');
});
