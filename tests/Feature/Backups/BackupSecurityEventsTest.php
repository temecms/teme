<?php

declare(strict_types=1);

use App\Domain\Security\SecurityEventType;

it('has a security log type for each backup event, none of them counted', function (): void {
    $values = [
        'backup.set_up', 'backup.destination_added', 'backup.destination_changed', 'backup.destination_removed',
        'backup.recovery_key_added', 'backup.recovery_key_replaced', 'backup.recovery_key_checked',
        'backup.restore_started', 'backup.restore_ended',
    ];

    foreach ($values as $value) {
        expect(SecurityEventType::tryFrom($value))->not->toBeNull("No type for [{$value}].")
            ->and(SecurityEventType::from($value)->isCounted())->toBeFalse();
    }
});
