<?php

declare(strict_types=1);

use Teme\Testing\TemeTestCase;
use TemeExamples\FirstPlugin\FirstPlugin;

uses(TemeTestCase::class);

// What the plugin heard lives in a static that outlives a test; empty it if an earlier test loaded the plugin.
beforeEach(function (): void {
    if (class_exists(FirstPlugin::class, false)) {
        FirstPlugin::$heard = [];
    }
});

it('hears an item being published', function (): void {
    $this->plugin(__DIR__.'/..')->enable();

    $item = $this->publish('Hello from a test');

    expect(FirstPlugin::$heard)->toHaveCount(1)
        ->and(FirstPlugin::$heard[0])->toContain($item->id);
});
