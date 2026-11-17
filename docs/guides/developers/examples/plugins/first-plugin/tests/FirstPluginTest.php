<?php

declare(strict_types=1);

use Teme\Testing\TemeTestCase;
use TemeExamples\FirstPlugin\FirstPlugin;

uses(TemeTestCase::class);

// What the plugin heard lives in a static, so each test starts from none.
beforeEach(function (): void {
    FirstPlugin::$heard = [];
});

it('hears an item being published', function (): void {
    $this->plugin(__DIR__.'/..')->enable();

    $item = $this->publish('Hello from a test');

    expect(FirstPlugin::$heard)->toHaveCount(1)
        ->and(FirstPlugin::$heard[0])->toContain($item->id);
});
