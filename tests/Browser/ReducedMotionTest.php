<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs the built CSS.
    $this->withVite();
});

it('shortens motion and keeps the spinner turning when the computer prefers reduced motion', function (): void {
    $page = visit('/admin/login', ['reducedMotion' => 'reduce']);
    cmWaitUntil($page, 'document.querySelector(\'input[name="email"]\') !== null');

    $motion = $page->script(<<<'JS'
        () => {
            const root = getComputedStyle(document.documentElement);
            const button = document.querySelector('[data-test="login-button"]');
            const spinner = document.createElement('span');
            spinner.className = 'animate-spin';
            spinner.setAttribute('data-test', 'reduced-motion-spinner');
            document.body.appendChild(spinner);
            const spin = getComputedStyle(spinner);

            return {
                prefersReduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                dur1: root.getPropertyValue('--dur-1').trim(),
                dur2: root.getPropertyValue('--dur-2').trim(),
                dur3: root.getPropertyValue('--dur-3').trim(),
                press: root.getPropertyValue('--press').trim(),
                buttonTransition: button === null ? null : getComputedStyle(button).transitionDuration,
                spinnerIteration: spin.animationIterationCount,
                spinnerDuration: spin.animationDuration,
            };
        }
        JS);

    expect($motion['prefersReduce'])->toBeTrue()
        ->and($motion['dur1'])->toBe('1ms')
        ->and($motion['dur2'])->toBe('1ms')
        ->and($motion['dur3'])->toBe('1ms')
        ->and($motion['press'])->toBe('1')
        ->and($motion['buttonTransition'])->toBe('0.001s')
        ->and($motion['spinnerIteration'])->toBe('infinite')
        ->and($motion['spinnerDuration'])->toBe('1s');
});
