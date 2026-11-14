<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

it('never lists a storage folder in application code', function (): void {
    $forbidden = '/->(files|allFiles|directories|allDirectories|listContents|deleteDirectory)\s*\(/';
    $offenders = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        if (preg_match($forbidden, $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
