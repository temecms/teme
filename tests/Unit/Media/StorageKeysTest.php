<?php

declare(strict_types=1);

use App\Domain\Media\StorageKeys;

it('slugs the uploaded name and never keeps its extension', function (): void {
    expect(StorageKeys::name('Summer Holiday (2).JPG'))->toBe('summer-holiday-2')
        ->and(StorageKeys::name('shell.php.jpg'))->toBe('shellphp')
        ->and(StorageKeys::name('....'))->toBe('file')
        ->and(strlen(StorageKeys::name(str_repeat('a', 300).'.png')))->toBe(80);
});

it('builds versioned keys under the item ID', function (): void {
    expect(StorageKeys::original('01K5', 2, 'holiday', 'jpg'))->toBe('01K5/v2/holiday.jpg')
        ->and(StorageKeys::variant('01K5', 2, 'holiday', 'w640'))->toBe('01K5/v2/holiday-w640.webp');
});
