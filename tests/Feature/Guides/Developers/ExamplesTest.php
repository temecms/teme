<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Testing\InteractsWithTeme;
use TemeExamples\FirstPlugin\FirstPlugin;

uses(RefreshDatabase::class, InteractsWithTeme::class);

it('finds, validates, enables and runs the first-plugin example', function (): void {
    // Discovery only accepts folders that hold a manifest: the example
    // package itself, under docs/guides/developers/examples/plugins/.
    $plugin = $this->plugin(base_path('docs/guides/developers/examples/plugins/first-plugin'));

    expect($plugin->name)->toBe('teme-examples/first-plugin')
        ->and($plugin->code)->toBe('first-plugin');

    $plugin->enable();
    FirstPlugin::$heard = [];
    $item = $this->publish('Handbook example');

    expect(FirstPlugin::$heard)->toHaveCount(1)
        ->and(FirstPlugin::$heard[0])->toContain($item->id);
});

it('enables the first-plugin example with the command its page tells readers to run', function (): void {
    $this->plugin(base_path('docs/guides/developers/examples/plugins/first-plugin'));

    $this->artisan('teme:plugins:enable', [
        'name' => 'teme-examples/first-plugin',
        '--agree' => true,
    ])->assertSuccessful();
});

it('checks and draws the first-theme example', function (): void {
    $theme = $this->theme(base_path('docs/guides/developers/examples/themes/first-theme'));

    expect($theme->check())->toBe([]);

    $theme->activate();
    $this->publish('Handbook post');

    $this->fetch('/')
        ->assertOk()
        ->assertSee('data-template="index"', false);

    $this->fetch('/handbook-post/')
        ->assertOk()
        ->assertSee('data-template="single"', false)
        ->assertSee('Handbook post');
});
