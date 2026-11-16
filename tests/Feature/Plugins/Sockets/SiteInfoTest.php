<?php

declare(strict_types=1);

use App\Domain\Cache\PublicChanges;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Settings\SettingsWrite;
use App\Domain\Settings\SettingsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Teme\Contracts\Site\SiteInfo;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

/*
 * The site's public facts, offered to every plugin (docs/plans/plugin-sockets-1.md,
 * A5): the same answers in a request, a queued job and the console.
 */
beforeEach(function (): void {
    $changes = app(PublicChanges::class);
    $changes->open();

    try {
        DB::transaction(fn (): int => app(SettingsWriter::class)->write(new SettingsWrite('general', ['site_title' => 'Harbour Times'])));
    } finally {
        $changes->close();
    }

    $this->answers = function (): array {
        $site = app(PluginServices::class)->for('tests/hub', 'hub', [], [])[SiteInfo::class];

        return [$site->address(), $site->name(), $site->language(), $site->home(), $site->feeds()];
    };
});

it('is offered to a plugin that was granted nothing, and gives the same five answers in a request, a queued job and the console', function (): void {
    $answers = $this->answers;
    Route::get('/site-info-test', fn () => response()->json($answers()));
    Artisan::command('site-info-test', function () use ($answers): void {
        cache()->put('site-info-console', $answers());
    });
    $expected = ['http://localhost', 'Harbour Times', 'en', 'http://localhost/', ['http://localhost/feed/']];

    expect($this->get('/site-info-test')->json())->toBe($expected);

    dispatch(function () use ($answers): void {
        cache()->put('site-info-job', $answers());
    });
    $this->artisan('site-info-test')->assertSuccessful();

    expect(cache()->get('site-info-job'))->toBe($expected)
        ->and(cache()->get('site-info-console'))->toBe($expected);
});

it('adds the feed of each other language readers are shown, after the default language’s', function (): void {
    Languages::set(['fr', 'de'], ['de' => 'deutsch'], hidden: ['de']);
    expect(($this->answers)()[4])->toBe(['http://localhost/feed/', 'http://localhost/fr/feed/']);

    Languages::set(['fr', 'de'], ['de' => 'deutsch']);
    expect(($this->answers)()[4])->toBe(['http://localhost/feed/', 'http://localhost/fr/feed/', 'http://localhost/deutsch/feed/']);
});
