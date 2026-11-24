<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * docker/teme/lib/queue-workers runs here for real, with a stand-in worker
 * that writes a line when it starts, when it is told to stop, and when it
 * has finished, so what the image's worker would do can be read back.
 */

beforeEach(function (): void {
    $this->folder = sys_get_temp_dir().'/teme-queue-workers-'.bin2hex(random_bytes(4));
    mkdir($this->folder);
    file_put_contents($this->folder.'/worker', <<<'SH'
        #!/bin/bash
        log="$(dirname "$0")/log"
        echo "start $$" >> "$log"
        trap 'echo "stop $$" >> "$log"; sleep 0.3; echo "done $$" >> "$log"; exit 0' TERM
        while :; do sleep 0.05; done
        SH);
    chmod($this->folder.'/worker', 0755);

    $this->launch = function (string|false $workers): Process {
        $process = new Process([base_path('docker/teme/lib/queue-workers'), $this->folder.'/worker'], $this->folder, ['TEME_QUEUE_WORKERS' => $workers]);
        $process->start();

        return $process;
    };

    $this->lines = fn (string $word): array => array_values(array_filter(
        explode("\n", (string) @file_get_contents($this->folder.'/log')),
        fn (string $line): bool => str_starts_with($line, $word.' '),
    ));

    $this->waitFor = function (callable $done): void {
        for ($tries = 0; $tries < 100 && ! $done(); $tries++) {
            usleep(50_000);
        }
    };
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->folder.'/*') ?: []);
    rmdir($this->folder);
});

it('starts the worker once when TEME_QUEUE_WORKERS is unset, as before (#701)', function (): void {
    $process = ($this->launch)(false);
    ($this->waitFor)(fn (): bool => count(($this->lines)('start')) === 1);

    $started = ($this->lines)('start');
    // Once, the script becomes the worker itself: the worker's own process is the one started.
    expect($started)->toBe(['start '.$process->getPid()]);

    $process->signal(SIGTERM);
    $process->wait();
});

it('starts as many workers as TEME_QUEUE_WORKERS asks, starts one again when it ends, and stops them all cleanly (#701)', function (): void {
    $process = ($this->launch)('3');
    ($this->waitFor)(fn (): bool => count(($this->lines)('start')) === 3);
    $first = ($this->lines)('start');

    // One worker ends, as each does after its hour: another takes its place.
    posix_kill((int) explode(' ', $first[0])[1], SIGTERM);
    ($this->waitFor)(fn (): bool => count(($this->lines)('start')) === 4);

    expect($first)->toHaveCount(3)
        ->and(($this->lines)('start'))->toHaveCount(4);

    $process->signal(SIGTERM);
    $process->wait();

    // Each of the three still running was told to stop and finished first.
    expect($process->getExitCode())->toBe(0)
        ->and(($this->lines)('stop'))->toHaveCount(4)
        ->and(($this->lines)('done'))->toHaveCount(4);
});

it('refuses a number of workers outside 1 to 8, starting none (#701)', function (string $workers): void {
    $process = ($this->launch)($workers);
    $process->wait();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toBe("TEME_QUEUE_WORKERS is \"{$workers}\": set a whole number from 1 to 8.\n")
        ->and(($this->lines)('start'))->toBe([]);
})->with(['0', '9', 'four', '2.5']);
