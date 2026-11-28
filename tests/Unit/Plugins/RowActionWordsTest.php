<?php

/**
 * Core defaults for a custom row action’s confirm, toast and queued words.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Area\RowActionWords;

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
