<?php

/**
 * Core defaults and plugin-declared words for custom row actions.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Area\RowActionWords;

it('uses core defaults when a confirming action declares only a sentence', function (): void {
    $confirm = RowActionWords::confirm(
        'Reset statistics…',
        'Its impressions go back to zero.',
        fn (string $english): string => $english,
    );

    expect($confirm)->toBe([
        'title' => 'Run “Reset statistics…” on “{name}”?',
        'text' => 'Its impressions go back to zero.',
        'button' => 'Reset statistics',
        'cancel' => 'Cancel',
    ])
        ->and(RowActionWords::toast('Reset statistics…', null, fn (string $english): string => $english))
        ->toBe('Queued: Reset statistics… for “{name}”.')
        ->and(RowActionWords::queued('Reset statistics…', null, fn (string $english): string => $english))
        ->toBe('Queued');
});

it('keeps a plugin’s own confirm words and toast without rewriting the label', function (): void {
    $confirm = RowActionWords::confirm(
        'Reset statistics…',
        [
            'title' => 'Reset the statistics for “{name}”?',
            'text' => 'Its impressions and clicks go back to zero for every period. This can’t be undone.',
            'button' => 'Reset statistics',
            'cancel' => 'Keep them',
        ],
        fn (string $english): string => $english,
    );

    expect($confirm)->toBe([
        'title' => 'Reset the statistics for “{name}”?',
        'text' => 'Its impressions and clicks go back to zero for every period. This can’t be undone.',
        'button' => 'Reset statistics',
        'cancel' => 'Keep them',
    ])
        ->and(RowActionWords::toast(
            'Check the ad code',
            'Check queued for “{name}”. Its status updates when Placard has finished.',
            fn (string $english): string => $english,
        ))->toBe('Check queued for “{name}”. Its status updates when Placard has finished.')
        ->and(RowActionWords::fill(
            'Check queued for “{name}”. Its status updates when Placard has finished.',
            ['name' => 'Summer sale'],
        ))->toBe('Check queued for “Summer sale”. Its status updates when Placard has finished.')
        ->and(RowActionWords::queued('Check the ad code', 'Check queued', fn (string $english): string => $english))
        ->toBe('Check queued');
});

it('answers null confirm when the action does not ask first', function (): void {
    expect(RowActionWords::confirm('Check the ad code', null, fn (string $english): string => $english))->toBeNull();
});

it('fills core defaults for confirm, toast, queued, Cancel, button and empty:true wording', function (): void {
    $words = static fn (string $english): string => $english;

    expect(RowActionWords::confirm('Reset statistics…', [], $words))->toBe([
        'title' => 'Run “Reset statistics…” on “{name}”?',
        'text' => null,
        'button' => 'Reset statistics',
        'cancel' => 'Cancel',
    ])
        ->and(RowActionWords::toast('Check the ad code', null, $words))->toBe('Queued: Check the ad code for “{name}”.')
        ->and(RowActionWords::queued('Check the ad code', null, $words))->toBe('Queued')
        ->and(RowActionWords::buttonLabel('Reset statistics…'))->toBe('Reset statistics');
});
