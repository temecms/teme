<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('flags model writes from the HTTP and Actions layers, and nothing else', function (): void {
    $process = new Process(
        [
            PHP_BINARY,
            'vendor/phpstan/phpstan/phpstan',
            'analyse',
            '--configuration=tests/PHPStan/fixtures.neon',
            '--error-format=json',
            '--no-progress',
            '--memory-limit=1G',
        ],
        dirname(__DIR__, 2),
    );
    $process->setTimeout(300);
    $process->run();

    /** @var array{files: array<string, array{messages: list<array{message: string, identifier?: string}>}>} $report */
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $flagged = [];
    foreach ($report['files'] as $path => $file) {
        $flagged[basename($path)] = array_map(
            static fn (array $message): string => $message['identifier'] ?? 'no-identifier',
            $file['messages'],
        );
    }
    ksort($flagged);

    expect($flagged)->toBe([
        'CreatesUserStatically.php' => ['teme.modelWriteInHttpLayer', 'teme.modelWriteInHttpLayer'],
        'WritesThroughRequestUser.php' => [
            'teme.modelWriteInHttpLayer',
            'teme.modelWriteInHttpLayer',
            'teme.modelWriteInHttpLayer',
        ],
    ]);
});
